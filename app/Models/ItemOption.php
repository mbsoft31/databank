<?php
// app/Models/ItemOption.php
namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;

class ItemOption extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'itemable_id',
        'itemable_type',
        'text_ar',
        'latex',
        'is_correct',
        'order_index',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'order_index' => 'integer',
    ];

    protected $appends = [
        'option_letter',
        'has_latex',
        'display_text',
    ];

    // Relationships
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    // Scopes
    public function scopeCorrect(Builder $query): Builder
    {
        return $query->where('is_correct', true);
    }

    public function scopeIncorrect(Builder $query): Builder
    {
        return $query->where('is_correct', false);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order_index');
    }

    public function scopeForItem(Builder $query, string $itemType, string $itemId): Builder
    {
        return $query->where('itemable_type', $itemType)
            ->where('itemable_id', $itemId);
    }

    // Accessors
    public function getOptionLetterAttribute(): string
    {
        // Convert order_index to letter (0 = A, 1 = B, etc.)
        return chr(65 + $this->order_index);
    }

    public function getHasLatexAttribute(): bool
    {
        return !empty($this->latex);
    }

    public function getDisplayTextAttribute(): string
    {
        return $this->text_ar . ($this->has_latex ? ' [Math]' : '');
    }

    // Helper Methods
    public function isCorrect(): bool
    {
        return $this->is_correct;
    }

    public function isIncorrect(): bool
    {
        return !$this->is_correct;
    }

    public function moveUp(): bool
    {
        if ($this->order_index <= 0) {
            return false;
        }

        $siblingOption = static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('order_index', $this->order_index - 1)
            ->first();

        if ($siblingOption) {
            $temp = $this->order_index;
            $this->order_index = $siblingOption->order_index;
            $siblingOption->order_index = $temp;

            $this->save();
            $siblingOption->save();

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

        $siblingOption = static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('order_index', $this->order_index + 1)
            ->first();

        if ($siblingOption) {
            $temp = $this->order_index;
            $this->order_index = $siblingOption->order_index;
            $siblingOption->order_index = $temp;

            $this->save();
            $siblingOption->save();

            return true;
        }

        return false;
    }

    public function getSiblingOptions(): Collection
    {
        return static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('id', '!=', $this->id)
            ->ordered()
            ->get();
    }

    public function getCorrectSiblings(): Collection
    {
        return $this->getSiblingOptions()->where('is_correct', true);
    }

    public function getIncorrectSiblings(): Collection
    {
        return $this->getSiblingOptions()->where('is_correct', false);
    }

    // Validation Methods
    public function validateContent(): array
    {
        $errors = [];

        if (empty(trim($this->text_ar))) {
            $errors[] = 'Option text cannot be empty';
        }

        if (strlen($this->text_ar) < 2) {
            $errors[] = 'Option text too short';
        }

        if (strlen($this->text_ar) > 500) {
            $errors[] = 'Option text too long';
        }

        // Check for duplicate content within the same item
        $duplicates = static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('text_ar', $this->text_ar)
            ->where('id', '!=', $this->id)
            ->exists();

        if ($duplicates) {
            $errors[] = 'Duplicate option text found';
        }

        return $errors;
    }

    public function isValid(): bool
    {
        return empty($this->validateContent());
    }

    // Static Methods
    public static function reorderForItem(string $itemType, string $itemId, array $optionIds): bool
    {
        $options = static::where('itemable_type', $itemType)
            ->where('itemable_id', $itemId)
            ->whereIn('id', $optionIds)
            ->get()
            ->keyBy('id');

        if ($options->count() !== count($optionIds)) {
            return false;
        }

        foreach ($optionIds as $index => $optionId) {
            if (isset($options[$optionId])) {
                $options[$optionId]->update(['order_index' => $index]);
            }
        }

        return true;
    }

    public static function createBatch(string $itemType, string $itemId, array $optionsData): Collection
    {
        $options = collect();

        foreach ($optionsData as $index => $optionData) {
            $option = static::create([
                'itemable_type' => $itemType,
                'itemable_id' => $itemId,
                'text_ar' => $optionData['text_ar'],
                'latex' => $optionData['latex'] ?? null,
                'is_correct' => $optionData['is_correct'] ?? false,
                'order_index' => $index,
            ]);

            $options->push($option);
        }

        return Collection::make($options);
    }

    public static function getStatsForItem(string $itemType, string $itemId): array
    {
        $query = static::where('itemable_type', $itemType)
            ->where('itemable_id', $itemId);

        return [
            'total_options' => $query->count(),
            'correct_options' => $query->where('is_correct', true)->count(),
            'incorrect_options' => $query->where('is_correct', false)->count(),
            'with_latex' => $query->whereNotNull('latex')->count(),
        ];
    }
}
