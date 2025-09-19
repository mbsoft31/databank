<?php
// app/Services/DuplicateDetectionService.php
namespace App\Services;

use App\Models\ItemDraft;
use App\Models\ContentHash;

class DuplicateDetectionService
{
    public function generateContentHash(ItemDraft $itemDraft): void
    {
        $normalized = $this->normalizeContent($itemDraft->stem_ar);
        $hash = hash('sha256', $normalized);
        $tokens = $this->generateSimilarityTokens($normalized);

        ContentHash::updateOrCreate(
            ['item_draft_id' => $itemDraft->id],
            [
                'content_hash' => $hash,
                'normalized_content' => $normalized,
                'similarity_tokens' => $tokens,
            ]
        );
    }

    public function findSimilarContent(ItemDraft $itemDraft, float $threshold = 0.8): array
    {
        $contentHash = $itemDraft->contentHash;
        if (!$contentHash) return [];

        $candidates = ContentHash::where('item_draft_id', '!=', $itemDraft->id)->get();
        $similar = [];

        foreach ($candidates as $candidate) {
            $similarity = $this->jaccardSimilarity(
                $contentHash->similarity_tokens,
                $candidate->similarity_tokens
            );

            if ($similarity >= $threshold) {
                $similar[] = [
                    'item_draft_id' => $candidate->item_draft_id,
                    'similarity_score' => $similarity,
                ];
            }
        }

        return $similar;
    }

    private function normalizeContent(string $content): string
    {
        // Remove Arabic diacritics, normalize whitespace, etc.
        return trim(preg_replace('/\s+/', ' ', $content));
    }

    private function generateSimilarityTokens(string $content): array
    {
        // Generate n-grams for similarity comparison
        return array_unique(str_split($content, 3));
    }

    private function jaccardSimilarity(array $set1, array $set2): float
    {
        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));
        return count($union) > 0 ? count($intersection) / count($union) : 0;
    }
}
