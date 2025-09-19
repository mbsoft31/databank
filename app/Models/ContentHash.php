<?php
// app/Models/ContentHash.php
namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{DB};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContentHash extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'item_draft_id',
        'content_hash',
        'normalized_content',
        'similarity_tokens',
    ];

    protected $casts = [
        'similarity_tokens' => 'array',
    ];

    protected $appends = [
        'token_count',
        'content_length',
        'hash_algorithm',
        'uniqueness_score',
        'duplicate_count',
    ];

    public const SIMILARITY_THRESHOLD = 0.8;
    public const MIN_CONTENT_LENGTH = 10;
    public const NGRAM_SIZE = 3;
    public const MAX_TOKENS = 1000;

    // Relationships
    public function itemDraft(): BelongsTo
    {
        return $this->belongsTo(ItemDraft::class);
    }

    // Scopes
    public function scopeByHash(Builder $query, string $hash): Builder
    {
        return $query->where('content_hash', $hash);
    }

    public function scopeDuplicates(Builder $query): Builder
    {
        return $query->select('content_hash', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('content_hash')
            ->having('duplicate_count', '>', 1);
    }

    public function scopeForContent(Builder $query, string $content): Builder
    {
        $hash = static::generateHash($content);
        return $query->where('content_hash', $hash);
    }

    public function scopeByItemDraft(Builder $query, string $itemDraftId): Builder
    {
        return $query->where('item_draft_id', $itemDraftId);
    }

    public function scopeWithSimilarity(Builder $query, array $tokens, float $threshold = self::SIMILARITY_THRESHOLD): Builder
    {
        // This would require a more complex query for Jaccard similarity
        // For now, we'll use a simpler approach with JSON operations
        return $query->whereRaw('JSON_LENGTH(similarity_tokens) > 0');
    }

    public function scopeRecentlyCreated(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeHighSimilarity(Builder $query, float $minSimilarity = 0.7): Builder
    {
        // This would need custom similarity calculation
        return $query->whereNotNull('similarity_tokens');
    }

    // Accessors
    public function getTokenCountAttribute(): int
    {
        return count($this->similarity_tokens ?? []);
    }

    public function getContentLengthAttribute(): int
    {
        return strlen($this->normalized_content);
    }

    public function getHashAlgorithmAttribute(): string
    {
        return 'sha256';
    }

    public function getUniquenessScoreAttribute(): float
    {
        // Calculate uniqueness based on how many other items share similar tokens
        $totalItems = static::count();
        $similarItems = $this->findSimilarItems(0.3)->count();

        return $totalItems > 0 ? round((1 - $similarItems / $totalItems) * 100, 2) : 100;
    }

    public function getDuplicateCountAttribute(): int
    {
        return static::where('content_hash', $this->content_hash)->count() - 1;
    }

    // Helper Methods
    public function isUnique(): bool
    {
        return $this->duplicate_count === 0;
    }

    public function isDuplicate(): bool
    {
        return $this->duplicate_count > 0;
    }

    public function getExactDuplicates(): Collection
    {
        return static::where('content_hash', $this->content_hash)
            ->where('id', '!=', $this->id)
            ->with('itemDraft')
            ->get();
    }

    public function findSimilarItems(float $threshold = self::SIMILARITY_THRESHOLD): Collection
    {
        if (empty($this->similarity_tokens)) {
            return Collection::empty();
        }

        // Get all other content hashes
        $others = static::where('id', '!=', $this->id)
            ->whereNotNull('similarity_tokens')
            ->get();

        $similar = collect();

        foreach ($others as $other) {
            $similarity = $this->calculateJaccardSimilarity($this->similarity_tokens, $other->similarity_tokens);

            if ($similarity >= $threshold) {
                $similar->push([
                    'content_hash' => $other,
                    'similarity_score' => $similarity,
                ]);
            }
        }

        return Collection::make($similar->sortByDesc('similarity_score'));
    }

    public function calculateJaccardSimilarity(array $set1, array $set2): float
    {
        if (empty($set1) && empty($set2)) {
            return 1.0;
        }

        if (empty($set1) || empty($set2)) {
            return 0.0;
        }

        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    public function calculateCosineSimilarity(array $tokens1, array $tokens2): float
    {
        // Convert token arrays to frequency vectors
        $vocab = array_unique(array_merge($tokens1, $tokens2));
        $vector1 = [];
        $vector2 = [];

        foreach ($vocab as $token) {
            $vector1[] = array_count_values($tokens1)[$token] ?? 0;
            $vector2[] = array_count_values($tokens2)[$token] ?? 0;
        }

        // Calculate cosine similarity
        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $norm1 += $vector1[$i] * $vector1[$i];
            $norm2 += $vector2[$i] * $vector2[$i];
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        return ($norm1 * $norm2) > 0 ? $dotProduct / ($norm1 * $norm2) : 0.0;
    }

    public function refreshHash(): bool
    {
        if (!$this->itemDraft) {
            return false;
        }

        $newNormalized = static::normalizeContent($this->itemDraft->stem_ar);
        $newHash = static::generateHash($newNormalized);
        $newTokens = static::generateSimilarityTokens($newNormalized);

        return $this->update([
            'content_hash' => $newHash,
            'normalized_content' => $newNormalized,
            'similarity_tokens' => $newTokens,
        ]);
    }

    public function getSimilarityReport(): array
    {
        $similar = $this->findSimilarItems();
        $exact = $this->getExactDuplicates();

        return [
            'item_draft_id' => $this->item_draft_id,
            'content_hash' => $this->content_hash,
            'is_unique' => $this->isUnique(),
            'duplicate_count' => $this->duplicate_count,
            'uniqueness_score' => $this->uniqueness_score,
            'exact_duplicates' => $exact->map(function($dup) {
                return [
                    'item_draft_id' => $dup->item_draft_id,
                    'item_title' => Str::limit($dup->itemDraft->stem_ar ?? '', 50),
                    'created_at' => $dup->created_at->toDateString(),
                ];
            }),
            'similar_items' => $similar->take(5)->map(function($sim) {
                return [
                    'item_draft_id' => $sim['content_hash']->item_draft_id,
                    'similarity_score' => round($sim['similarity_score'], 3),
                    'item_title' => Str::limit($sim['content_hash']->itemDraft->stem_ar ?? '', 50),
                ];
            }),
            'content_preview' => Str::limit($this->normalized_content),
            'token_count' => $this->token_count,
            'content_length' => $this->content_length,
        ];
    }

    public function getContentAnalysis(): array
    {
        $content = $this->normalized_content;

        return [
            'character_count' => strlen($content),
            'word_count' => str_word_count($content),
            'unique_words' => count(array_unique(explode(' ', $content))),
            'token_count' => $this->token_count,
            'avg_word_length' => strlen($content) > 0 ? round(strlen(str_replace(' ', '', $content)) / max(1, str_word_count($content)), 2) : 0,
            'complexity_indicators' => [
                'has_equations' => str_contains($content, '='),
                'has_numbers' => preg_match('/\d/', $content),
                'has_arabic' => preg_match('/[\x{0600}-\x{06FF}]/u', $content),
                'has_mathematical_symbols' => preg_match('/[×÷±∑∫√π∞]/', $content),
            ],
            'content_type_hints' => [
                'likely_equation' => str_contains($content, '=') && preg_match('/\d/', $content),
                'likely_geometry' => str_contains($content, 'مثلث') || str_contains($content, 'دائرة'),
                'likely_calculus' => str_contains($content, 'مشتقة') || str_contains($content, 'تكامل'),
                'likely_algebra' => str_contains($content, 'معادلة') || str_contains($content, 'x'),
            ],
        ];
    }

    // Static Methods
    public static function generateHash(string $content): string
    {
        $normalized = static::normalizeContent($content);
        return hash('sha256', $normalized);
    }

    public static function normalizeContent(string $content): string
    {
        // Remove Arabic diacritics (Tashkeel)
        $content = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $content);

        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content);

        // Remove common punctuation but keep mathematical symbols
        $content = preg_replace('/[،؛؟!"\'()[\]{}]/', '', $content);

        // Convert English digits to Arabic
        /*$content = strtr($content, '0123456789', '٠١٢٣٤٥٦٧٨٩');*/

        // Trim and lowercase
        return trim(mb_strtolower($content));
    }

    public static function generateSimilarityTokens(string $normalizedContent): array
    {
        if (strlen($normalizedContent) < self::MIN_CONTENT_LENGTH) {
            return [];
        }

        $tokens = [];

        // Character-level n-grams
        $length = mb_strlen($normalizedContent);
        for ($i = 0; $i <= $length - self::NGRAM_SIZE; $i++) {
            $ngram = mb_substr($normalizedContent, $i, self::NGRAM_SIZE);
            $tokens[] = $ngram;
        }

        // Word-level tokens
        $words = explode(' ', $normalizedContent);
        foreach ($words as $word) {
            if (strlen($word) >= 3) {
                $tokens[] = $word;
            }
        }

        // Remove duplicates and limit size
        $tokens = array_unique($tokens);

        if (count($tokens) > self::MAX_TOKENS) {
            // Keep most frequent tokens
            $tokenCounts = array_count_values($tokens);
            arsort($tokenCounts);
            $tokens = array_keys(array_slice($tokenCounts, 0, self::MAX_TOKENS));
        }

        return array_values($tokens);
    }

    public static function createForItem(ItemDraft $itemDraft): self
    {
        $content = $itemDraft->stem_ar ?? '';

        // Add options content for more comprehensive hashing
        $options = $itemDraft->options()->pluck('text_ar')->join(' ');
        if ($options) {
            $content .= ' ' . $options;
        }

        $normalized = static::normalizeContent($content);
        $hash = static::generateHash($normalized);
        $tokens = static::generateSimilarityTokens($normalized);

        return static::updateOrCreate(
            ['item_draft_id' => $itemDraft->id],
            [
                'content_hash' => $hash,
                'normalized_content' => $normalized,
                'similarity_tokens' => $tokens,
            ]
        );
    }

    public static function findDuplicatesForContent(string $content, float $threshold = self::SIMILARITY_THRESHOLD): \Illuminate\Support\Collection
    {
        $normalized = static::normalizeContent($content);
        $tokens = static::generateSimilarityTokens($normalized);

        if (empty($tokens)) {
            return collect();
        }

        $allHashes = static::whereNotNull('similarity_tokens')->get();
        $similar = collect();

        foreach ($allHashes as $hash) {
            $similarity = static::calculateJaccardSimilarityStatic($tokens, $hash->similarity_tokens);

            if ($similarity >= $threshold) {
                $similar->push([
                    'content_hash' => $hash,
                    'similarity_score' => $similarity,
                ]);
            }
        }

        return $similar->sortByDesc('similarity_score');
    }

    private static function calculateJaccardSimilarityStatic(array $set1, array $set2): float
    {
        if (empty($set1) && empty($set2)) return 1.0;
        if (empty($set1) || empty($set2)) return 0.0;

        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    public static function getStatistics(): array
    {
        return [
            'total_hashes' => static::count(),
            'unique_content' => static::distinct('content_hash')->count(),
            'duplicate_groups' => static::duplicates()->count(),
            'avg_content_length' => round(static::avg(DB::raw('LENGTH(normalized_content)')), 2),
            'avg_token_count' => round(static::avg(DB::raw('JSON_LENGTH(similarity_tokens)')), 2),
            'content_distribution' => [
                'very_short' => static::whereRaw('LENGTH(normalized_content) < 50')->count(),
                'short' => static::whereRaw('LENGTH(normalized_content) BETWEEN 50 AND 150')->count(),
                'medium' => static::whereRaw('LENGTH(normalized_content) BETWEEN 151 AND 300')->count(),
                'long' => static::whereRaw('LENGTH(normalized_content) > 300')->count(),
            ],
            'recently_created' => static::recentlyCreated()->count(),
        ];
    }

    public static function findPotentialDuplicates(float $threshold = self::SIMILARITY_THRESHOLD): \Illuminate\Support\Collection
    {
        $duplicateGroups = collect();
        $processed = collect();

        static::chunk(100, function($hashes) use (&$duplicateGroups, &$processed, $threshold) {
            foreach ($hashes as $hash) {
                if ($processed->contains($hash->id)) {
                    continue;
                }

                $similar = $hash->findSimilarItems($threshold);

                if ($similar->isNotEmpty()) {
                    $group = Collection::make([$hash])->concat($similar->pluck('content_hash')->toArray());
                    $duplicateGroups->push($group);
                    $processed = $processed->concat($group->pluck('id'));
                }
            }
        });

        return $duplicateGroups;
    }

    public static function cleanup(): int
    {
        // Remove hashes for deleted item drafts
        $orphaned = static::whereDoesntHave('itemDraft')->count();
        static::whereDoesntHave('itemDraft')->delete();

        return $orphaned;
    }

    public static function rebuildAll(): int
    {
        $rebuilt = 0;

        static::with('itemDraft')->chunk(100, function($hashes) use (&$rebuilt) {
            foreach ($hashes as $hash) {
                if ($hash->itemDraft && $hash->refreshHash()) {
                    $rebuilt++;
                }
            }
        });

        return $rebuilt;
    }
}
