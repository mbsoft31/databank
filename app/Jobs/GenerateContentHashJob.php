<?php
// app/Jobs/GenerateContentHashJob.php
namespace App\Jobs;

use App\Models\ItemDraft;
use App\Services\DuplicateDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateContentHashJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected ItemDraft $itemDraft) {}

    public function handle(DuplicateDetectionService $service): void
    {
        $service->generateContentHash($this->itemDraft);
    }
}
