<?php
// app/Jobs/GenerateExportJob.php
namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Services\ExportService;
use App\Services\MathRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Export $export) {}

    public function handle(ExportService $exportService, MathRenderingService $mathService): void
    {
        try {
            $this->export->update(['status' => ExportStatus::Processing]);

            $filePath = match ($this->export->kind) {
                'worksheet_markdown' => $exportService->generateMarkdown($this->export),
                'worksheet_html' => $exportService->generateHTML($this->export, $mathService),
                'worksheet_pdf' => $exportService->generatePDF($this->export, $mathService),
                default => throw new \InvalidArgumentException("Unknown export kind: {$this->export->kind}")
            };

            $this->export->update([
                'status' => ExportStatus::Completed,
                'file_path' => $filePath,
                'completed_at' => now(),
            ]);

        } catch (\Exception $e) {
            $this->export->update([
                'status' => ExportStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->export->update([
            'status' => ExportStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
