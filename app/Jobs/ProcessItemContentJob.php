<?php
// app/Jobs/ProcessItemContentJob.php
namespace App\Jobs;

use App\Models\{ItemDraft, ContentHash, MathCache};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

class ProcessItemContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ItemDraft $itemDraft;
    public int $timeout = 60;
    public int $tries = 2;

    public function __construct(ItemDraft $itemDraft)
    {
        $this->itemDraft = $itemDraft;
        $this->onQueue('content-processing');
    }

    public function handle(): void
    {
        try {
            // Generate content hash for duplicate detection
            ContentHash::createForItem($this->itemDraft);

            // Pre-render LaTeX if present
            if ($this->itemDraft->latex) {
                MathCache::getOrRender($this->itemDraft->latex, 'mathjax', true);
                MathCache::getOrRender($this->itemDraft->latex, 'katex', false);
            }

            // Process options with LaTeX
            foreach ($this->itemDraft->options as $option) {
                if ($option->latex) {
                    MathCache::getOrRender($option->latex, 'mathjax', false);
                }
            }

            // Process hints with LaTeX
            foreach ($this->itemDraft->hints as $hint) {
                if ($hint->latex) {
                    MathCache::getOrRender($hint->latex, 'mathjax', false);
                }
            }

            // Process solutions with LaTeX
            foreach ($this->itemDraft->solutions as $solution) {
                if ($solution->latex) {
                    MathCache::getOrRender($solution->latex, 'mathjax', true);
                }
            }

            Log::info("Item content processed successfully", [
                'item_draft_id' => $this->itemDraft->id,
                'has_latex' => !empty($this->itemDraft->latex),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to process item content", [
                'item_draft_id' => $this->itemDraft->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
