<?php
// app/Jobs/GenerateExportJob.php
namespace App\Jobs;

use App\Models\{Export, ItemProd};
use App\Services\ExportService;
use App\Enums\ExportStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{Log, Storage};
use Exception;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Export $export;
    public int $timeout = 300; // 5 minutes
    public int $tries = 3;

    public function __construct(Export $export)
    {
        $this->export = $export;
        $this->onQueue('exports');
    }

    public function handle(ExportService $exportService): void
    {
        try {
            // Mark as processing
            $this->export->markAsProcessing();

            // Get items to export
            $items = ItemProd::with(['concepts', 'tags', 'options', 'hints', 'solutions'])
                ->whereIn('id', $this->export->params['item_ids'] ?? [])
                ->get();

            if ($items->isEmpty()) {
                throw new Exception('No items found for export');
            }

            // Generate export based on kind
            $filePath = match($this->export->kind) {
                'worksheet_pdf' => $exportService->generateWorksheetPDF($items, $this->export->params),
                'worksheet_html' => $exportService->generateWorksheetHTML($items, $this->export->params),
                'worksheet_markdown' => $exportService->generateWorksheetMarkdown($items, $this->export->params),
                'item_bank_json' => $exportService->generateItemBankJSON($items, $this->export->params),
                default => throw new Exception("Unsupported export kind: {$this->export->kind}")
            };

            // Mark as completed
            $this->export->markAsCompleted($filePath);

            Log::info("Export completed successfully", [
                'export_id' => $this->export->id,
                'kind' => $this->export->kind,
                'item_count' => $items->count(),
                'file_path' => $filePath,
            ]);

        } catch (Exception $e) {
            $this->export->markAsFailed($e->getMessage());

            Log::error("Export failed", [
                'export_id' => $this->export->id,
                'kind' => $this->export->kind,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        $this->export->markAsFailed($exception->getMessage());

        Log::error("Export job failed permanently", [
            'export_id' => $this->export->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
