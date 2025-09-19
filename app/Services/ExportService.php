<?php
// app/Services/ExportService.php
namespace App\Services;

use App\Models\Export;
use App\Models\ItemProd;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ExportService
{
    public function generatePDF(Export $export, MathRenderingService $mathService): string
    {
        $params = $export->params;
        $items = ItemProd::with(['concepts', 'tags', 'options', 'hints', 'solutions'])
            ->whereIn('id', $params['item_ids'])
            ->get();

        // Render math content
        $processedItems = $items->map(function ($item) use ($mathService) {
            if ($item->latex) {
                $item->rendered_latex = $mathService->renderLatex($item->latex, true);
            }
            $item->options->each(function ($option) use ($mathService) {
                if ($option->latex) {
                    $option->rendered_latex = $mathService->renderLatex($option->latex, false);
                }
            });
            return $item;
        });

        // Generate PDF
        $pdf = Pdf::loadView('exports.worksheet-pdf', [
            'items' => $processedItems,
            'title' => $params['title'] ?? 'Worksheet',
            'include_solutions' => $params['include_solutions'] ?? false,
        ]);

        $filename = "worksheet_{$export->id}.pdf";
        $path = "exports/pdf/{$filename}";
        Storage::put($path, $pdf->output());
        return $path;
    }
}
