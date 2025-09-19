<?php
// app/Models/ContentHash.php
namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{DB, Log};
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
    public function itemDraft()
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

    // Accessors
    public function getTokenCountAttribute(): int
    {
        return count($this->similarity_tokens ?? []);
    }

    public function getContentLengthAttribute(): int
    {
        return mb_strlen($this->normalized_content, 'UTF-8');
    }

    public function getHashAlgorithmAttribute(): string
    {
        return 'sha256';
    }

    public function getUniquenessScoreAttribute(): float
    {
        $totalItems = static::count();
        if ($totalItems <= 1) return 100.0;

        $similarItems = $this->findSimilarItems(0.3)->count();
        return round((1 - $similarItems / max(1, $totalItems - 1)) * 100, 2);
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

    public function getExactDuplicates(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('content_hash', $this->content_hash)
            ->where('id', '!=', $this->id)
            ->with('itemDraft')
            ->get();
    }

    public function findSimilarItems(float $threshold = self::SIMILARITY_THRESHOLD): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($this->similarity_tokens)) {
            return Collection::empty();
        }

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

    // Static Methods
    public static function generateHash(string $content): string
    {
        $normalized = static::normalizeContent($content);
        return hash('sha256', $normalized);
    }

    public static function normalizeContent(string $content): string
    {
        // Ensure UTF-8 encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        }

        // Remove Arabic diacritics (Tashkeel)
        $content = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $content);

        // Normalize whitespace
        $content = preg_replace('/\s+/u', ' ', $content);

        // Remove common punctuation but keep mathematical symbols
        $content = preg_replace('/[،؛؟!"\'()[\]{}]/u', '', $content);

        // Normalize Arabic numbers
        $content = strtr($content, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'
        ]);

        // Trim and lowercase (using mb_strtolower for Unicode support)
        return trim(mb_strtolower($content, 'UTF-8'));
    }

    public static function generateSimilarityTokens(string $normalizedContent): array
    {
        if (mb_strlen($normalizedContent, 'UTF-8') < self::MIN_CONTENT_LENGTH) {
            return [];
        }

        $tokens = [];

        // Ensure content is valid UTF-8
        if (!mb_check_encoding($normalizedContent, 'UTF-8')) {
            $normalizedContent = mb_convert_encoding($normalizedContent, 'UTF-8', 'auto');
        }

        // Character-level n-grams using mb_substr for proper Unicode handling
        $length = mb_strlen($normalizedContent, 'UTF-8');
        for ($i = 0; $i <= $length - self::NGRAM_SIZE; $i++) {
            $ngram = mb_substr($normalizedContent, $i, self::NGRAM_SIZE, 'UTF-8');

            // Validate UTF-8 for each n-gram
            if (mb_check_encoding($ngram, 'UTF-8') && trim($ngram) !== '') {
                $tokens[] = $ngram;
            }
        }

        // Word-level tokens
        $words = preg_split('/\s+/u', $normalizedContent, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word, 'UTF-8') >= 2 && mb_check_encoding($word, 'UTF-8')) {
                $tokens[] = $word;
            }
        }

        // Clean and validate tokens
        $validTokens = [];
        foreach (array_unique($tokens) as $token) {
            // Ensure token is valid UTF-8 and not empty
            if (mb_check_encoding($token, 'UTF-8') && trim($token) !== '') {
                $validTokens[] = $token;
            }
        }

        // Limit token count
        if (count($validTokens) > self::MAX_TOKENS) {
            $tokenCounts = array_count_values($validTokens);
            arsort($tokenCounts);
            $validTokens = array_keys(array_slice($tokenCounts, 0, self::MAX_TOKENS));
        }

        return array_values($validTokens);
    }

    public static function createForItem(ItemDraft $itemDraft): self
    {
        $content = $itemDraft->stem_ar ?? '';

        // Add options content for more comprehensive hashing
        $options = $itemDraft->options()->pluck('text_ar')->filter()->join(' ');
        if ($options) {
            $content .= ' ' . $options;
        }

        // Ensure content is valid UTF-8
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
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

    // Override the setAttribute method to ensure proper UTF-8 encoding
    public function setAttribute($key, $value)
    {
        if ($key === 'similarity_tokens' && is_array($value)) {
            // Ensure all tokens are valid UTF-8
            $value = array_filter($value, function($token) {
                return is_string($token) && mb_check_encoding($token, 'UTF-8');
            });
        }

        return parent::setAttribute($key, $value);
    }

    // Additional methods remain the same but with UTF-8 safety
    public function refreshHash(): bool
    {
        if (!$this->itemDraft) {
            return false;
        }

        $content = $this->itemDraft->stem_ar ?? '';

        // Ensure UTF-8 encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        }

        $newNormalized = static::normalizeContent($content);
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
            'content_preview' => Str::limit($this->normalized_content, 100),
            'token_count' => $this->token_count,
            'content_length' => $this->content_length,
        ];
    }
}
