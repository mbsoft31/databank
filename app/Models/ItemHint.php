<?php
// app/Models/ItemHint.php
namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;

class ItemHint extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'itemable_id',
        'itemable_type',
        'text_ar',
        'latex',
        'order_index',
    ];

    protected $casts = [
        'order_index' => 'integer',
    ];

    protected $appends = [
        'hint_number',
        'has_latex',
        'display_text',
        'word_count',
    ];

    // Relationships
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order_index');
    }

    public function scopeForItem(Builder $query, string $itemType, string $itemId): Builder
    {
        return $query->where('itemable_type', $itemType)
            ->where('itemable_id', $itemId);
    }

    public function scopeWithLatex(Builder $query): Builder
    {
        return $query->whereNotNull('latex');
    }

    public function scopeTextOnly(Builder $query): Builder
    {
        return $query->whereNull('latex');
    }

    // Accessors
    public function getHintNumberAttribute(): int
    {
        return $this->order_index + 1;
    }

    public function getHasLatexAttribute(): bool
    {
        return !empty($this->latex);
    }

    public function getDisplayTextAttribute(): string
    {
        $prefix = "تلميح {$this->hint_number}: ";
        return $prefix . $this->text_ar . ($this->has_latex ? ' [Math]' : '');
    }

    public function getWordCountAttribute(): int
    {
        return str_word_count(strip_tags($this->text_ar));
    }

    // Helper Methods
    public function moveUp(): bool
    {
        if ($this->order_index <= 0) {
            return false;
        }

        $siblingHint = static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('order_index', $this->order_index - 1)
            ->first();

        if ($siblingHint) {
            $temp = $this->order_index;
            $this->order_index = $siblingHint->order_index;
            $siblingHint->order_index = $temp;

            $this->save();
            $siblingHint->save();

            return true;
        }

        return false;
    }

    public function moveDown(): bool
    {
        $maxIndex = static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->max('order_index');

        if ($this->order_index >= $maxIndex) {
            return false;
        }

        $siblingHint = static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('order_index', $this->order_index + 1)
            ->first();

        if ($siblingHint) {
            $temp = $this->order_index;
            $this->order_index = $siblingHint->order_index;
            $siblingHint->order_index = $temp;

            $this->save();
            $siblingHint->save();

            return true;
        }

        return false;
    }

    public function getSiblingHints(): Collection
    {
        return static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('id', '!=', $this->id)
            ->ordered()
            ->get();
    }

    public function getHelpfulnessLevel(): string
    {
        $wordCount = $this->word_count;
        $hasLatex = $this->has_latex;

        if ($wordCount < 5 && !$hasLatex) {
            return 'minimal';
        } elseif ($wordCount < 15) {
            return 'brief';
        } elseif ($wordCount < 30) {
            return 'detailed';
        } else {
            return 'comprehensive';
        }
    }

    public function isProgressive(): bool
    {
        // Check if this hint builds on previous hints
        $previousHints = static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('order_index', '<', $this->order_index)
            ->ordered()
            ->get();

        if ($previousHints->isEmpty()) {
            return true; // First hint is always progressive
        }

        // Simple check: does this hint contain references to concepts from previous hints
        $previousContent = $previousHints->pluck('text_ar')->implode(' ');
        $currentWords = explode(' ', $this->text_ar);
        $previousWords = explode(' ', $previousContent);

        $commonWords = array_intersect($currentWords, $previousWords);

        // If more than 20% of words are common, consider it progressive
        return count($commonWords) / count($currentWords) > 0.2;
    }

    // Validation Methods
    public function validateContent(): array
    {
        $errors = [];

        if (empty(trim($this->text_ar))) {
            $errors[] = 'Hint text cannot be empty';
        }

        if (strlen($this->text_ar) < 5) {
            $errors[] = 'Hint text too short (minimum 5 characters)';
        }

        if (strlen($this->text_ar) > 1000) {
            $errors[] = 'Hint text too long (maximum 1000 characters)';
        }

        // Check if hint gives away the answer completely
        if ($this->revealsAnswer()) {
            $errors[] = 'Hint reveals the complete answer';
        }

        return $errors;
    }

    public function isValid(): bool
    {
        return empty($this->validateContent());
    }

    protected function revealsAnswer(): bool
    {
        // Load the parent item's correct options
        $item = $this->itemable;
        if (!$item || !method_exists($item, 'correctOptions')) {
            return false;
        }

        $correctOptions = $item->correctOptions;
        if ($correctOptions->isEmpty()) {
            return false;
        }

        // Simple check: if hint contains exact text from correct answer
        foreach ($correctOptions as $option) {
            if (str_contains(strtolower($this->text_ar), strtolower($option->text_ar))) {
                return true;
            }
        }

        return false;
    }

    // Static Methods
    public static function reorderForItem(string $itemType, string $itemId, array $hintIds): bool
    {
        $hints = static::where('itemable_type', $itemType)
            ->where('itemable_id', $itemId)
            ->whereIn('id', $hintIds)
            ->get()
            ->keyBy('id');

        if ($hints->count() !== count($hintIds)) {
            return false;
        }

        foreach ($hintIds as $index => $hintId) {
            if (isset($hints[$hintId])) {
                $hints[$hintId]->update(['order_index' => $index]);
            }
        }

        return true;
    }

    public static function createBatch(string $itemType, string $itemId, array $hintsData): Collection
    {
        $hints = collect();

        foreach ($hintsData as $index => $hintData) {
            $hint = static::create([
                'itemable_type' => $itemType,
                'itemable_id' => $itemId,
                'text_ar' => $hintData['text_ar'],
                'latex' => $hintData['latex'] ?? null,
                'order_index' => $index,
            ]);

            $hints->push($hint);
        }

        return Collection::make($hints);
    }

    public static function getStatsForItem(string $itemType, string $itemId): array
    {
        $query = static::where('itemable_type', $itemType)
            ->where('itemable_id', $itemId);

        $hints = $query->get();

        return [
            'total_hints' => $hints->count(),
            'with_latex' => $hints->where('has_latex', true)->count(),
            'avg_word_count' => $hints->avg('word_count'),
            'helpfulness_distribution' => $hints->groupBy('helpfulness_level')->map->count(),
            'progressive_hints' => $hints->filter->isProgressive()->count(),
        ];
    }

    public static function generateSuggestions(string $itemStem, string $itemType): array
    {
        // This would implement AI-based hint generation
        // For now, return template suggestions
        return [
            'starter_hints' => [
                'فكر في المفاهيم الأساسية المطلوبة لحل هذه المسألة',
                'ما المعطيات المتوفرة في السؤال؟',
                'حدد المطلوب إيجاده بدقة',
            ],
            'process_hints' => [
                'جرب استخدام القانون أو القاعدة المناسبة',
                'قم بترتيب المعطيات وتنظيمها',
                'فكر في الخطوات اللازمة خطوة بخطوة',
            ],
            'checking_hints' => [
                'تأكد من وحدات القياس',
                'راجع العمليات الحسابية',
                'هل الجواب منطقي؟',
            ],
        ];
    }
}
