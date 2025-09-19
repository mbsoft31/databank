<?php
// app/Models/ItemProd.php
namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class ItemProd extends Model
{
    use HasFactory, HasUuids, Searchable;

    protected $table = 'item_prod';

    protected $fillable = [
        'source_draft_id',
        'stem_ar',
        'latex',
        'item_type',
        'difficulty',
        'meta',
        'published_at',
        'published_by',
    ];

    protected $casts = [
        'difficulty' => 'float',
        'meta' => 'array',
        'published_at' => 'datetime',
    ];

    protected $appends = [
        'difficulty_level',
        'has_latex',
        'option_count',
        'concept_names',
        'tag_names',
    ];

    // Scout Search Configuration
    public function searchableAs(): string
    {
        return 'item_prod';
    }

    public function toSearchableArray(): array
    {
        $this->loadMissing(['concepts:id,code,name_ar', 'tags:id,code,name_ar']);

        return [
            'id' => $this->id,
            'stem_ar' => $this->stem_ar,
            'latex' => $this->latex,
            'item_type' => $this->item_type,
            'difficulty' => $this->difficulty,
            'concepts' => $this->concepts->pluck('code'),
            'concept_names' => $this->concepts->pluck('name_ar'),
            'tags' => $this->tags->pluck('code'),
            'tag_names' => $this->tags->pluck('name_ar'),
            'published_at' => $this->published_at?->timestamp,
            'grade' => $this->concepts->pluck('grade')->filter()->unique(),
            'strand' => $this->concepts->pluck('strand')->filter()->unique(),
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return !empty($this->stem_ar) && $this->published_at !== null;
    }

    // Relationships
    public function sourceDraft(): BelongsTo
    {
        return $this->belongsTo(ItemDraft::class, 'source_draft_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'item_prod_concepts');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'item_prod_tags');
    }

    public function options(): MorphMany
    {
        return $this->morphMany(ItemOption::class, 'itemable')->orderBy('order_index');
    }

    public function correctOptions(): MorphMany
    {
        return $this->morphMany(ItemOption::class, 'itemable')
            ->where('is_correct', true)
            ->orderBy('order_index');
    }

    public function incorrectOptions(): MorphMany
    {
        return $this->morphMany(ItemOption::class, 'itemable')
            ->where('is_correct', false)
            ->orderBy('order_index');
    }

    public function hints(): MorphMany
    {
        return $this->morphMany(ItemHint::class, 'itemable')->orderBy('order_index');
    }

    public function solutions(): MorphMany
    {
        return $this->morphMany(ItemSolution::class, 'itemable');
    }

    public function mediaAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'item_prod_media');
    }

    // Scopes
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('item_type', $type);
    }

    public function scopeByDifficultyRange(Builder $query, float $min, float $max): Builder
    {
        return $query->whereBetween('difficulty', [$min, $max]);
    }

    public function scopeEasy(Builder $query): Builder
    {
        return $query->where('difficulty', '<=', 2.0);
    }

    public function scopeMedium(Builder $query): Builder
    {
        return $query->whereBetween('difficulty', [2.1, 3.5]);
    }

    public function scopeHard(Builder $query): Builder
    {
        return $query->where('difficulty', '>', 3.5);
    }

    public function scopeWithConcepts(Builder $query): Builder
    {
        return $query->has('concepts');
    }

    public function scopeWithSolutions(Builder $query): Builder
    {
        return $query->has('solutions');
    }

    public function scopeWithHints(Builder $query): Builder
    {
        return $query->has('hints');
    }

    public function scopeRecentlyPublished(Builder $query, int $days = 30): Builder
    {
        return $query->where('published_at', '>=', now()->subDays($days));
    }

    public function scopeByGrade(Builder $query, string $grade): Builder
    {
        return $query->whereHas('concepts', function ($q) use ($grade) {
            $q->where('grade', $grade);
        });
    }

    public function scopeByStrand(Builder $query, string $strand): Builder
    {
        return $query->whereHas('concepts', function ($q) use ($strand) {
            $q->where('strand', $strand);
        });
    }

    public function scopeByTag(Builder $query, string $tagCode): Builder
    {
        return $query->whereHas('tags', function ($q) use ($tagCode) {
            $q->where('code', $tagCode);
        });
    }

    // Accessors
    public function getDifficultyLevelAttribute(): string
    {
        return match(true) {
            $this->difficulty <= 2.0 => 'Easy',
            $this->difficulty <= 3.5 => 'Medium',
            default => 'Hard'
        };
    }

    public function getHasLatexAttribute(): bool
    {
        return !empty($this->latex);
    }

    public function getOptionCountAttribute(): int
    {
        return $this->options()->count();
    }

    public function getConceptNamesAttribute(): array
    {
        return $this->concepts->pluck('name_ar')->toArray();
    }

    public function getTagNamesAttribute(): array
    {
        return $this->tags->pluck('name_ar')->toArray();
    }

    // Helper Methods
    public function hasCorrectAnswer(): bool
    {
        return $this->correctOptions()->exists();
    }

    public function getCorrectAnswers(): Collection
    {
        return $this->correctOptions;
    }

    public function isMultipleChoice(): bool
    {
        return $this->item_type === 'multiple_choice';
    }

    public function isShortAnswer(): bool
    {
        return $this->item_type === 'short_answer';
    }

    public function isEssay(): bool
    {
        return $this->item_type === 'essay';
    }

    public function isNumerical(): bool
    {
        return $this->item_type === 'numerical';
    }

    public function getGrades(): array
    {
        return $this->concepts->pluck('grade')->filter()->unique()->values()->toArray();
    }

    public function getStrands(): array
    {
        return $this->concepts->pluck('strand')->filter()->unique()->values()->toArray();
    }

    public function getPrimaryGrade(): ?string
    {
        $grades = $this->getGrades();
        return empty($grades) ? null : $grades[0];
    }

    public function getPrimaryStrand(): ?string
    {
        $strands = $this->getStrands();
        return empty($strands) ? null : $strands[0];
    }

    public function getQualityScore(): float
    {
        $score = 0;
        $maxScore = 0;

        // Has concepts (20 points)
        $maxScore += 20;
        if ($this->concepts()->count() > 0) $score += 20;

        // Has solutions (25 points)
        $maxScore += 25;
        if ($this->solutions()->count() > 0) $score += 25;

        // Has hints (15 points)
        $maxScore += 15;
        if ($this->hints()->count() > 0) $score += 15;

        // Multiple choice has correct answers (20 points)
        if ($this->isMultipleChoice()) {
            $maxScore += 20;
            if ($this->hasCorrectAnswer()) $score += 20;
        } else {
            $score += 20; // Non-MC items get full points
            $maxScore += 20;
        }

        // Has LaTeX formatting (10 points)
        $maxScore += 10;
        if ($this->has_latex) $score += 10;

        // Has tags (10 points)
        $maxScore += 10;
        if ($this->tags()->count() > 0) $score += 10;

        return $maxScore > 0 ? round(($score / $maxScore) * 100, 1) : 0;
    }

    public function getComplexityScore(): int
    {
        $complexity = 0;

        // Base complexity from difficulty
        $complexity += (int) ($this->difficulty * 10);

        // Add complexity for various components
        $complexity += $this->options()->count() * 2;
        $complexity += $this->hints()->count() * 3;
        $complexity += $this->solutions()->count() * 5;
        $complexity += $this->concepts()->count() * 2;

        // LaTeX adds complexity
        if ($this->has_latex) $complexity += 10;

        return $complexity;
    }

    public function getSimilarItems(int $limit = 5): Collection
    {
        return static::where('id', '!=', $this->id)
            ->where('item_type', $this->item_type)
            ->whereBetween('difficulty', [$this->difficulty - 0.5, $this->difficulty + 0.5])
            ->whereHas('concepts', function ($q) {
                $q->whereIn('concepts.id', $this->concepts->pluck('id'));
            })
            ->with(['concepts', 'tags'])
            ->limit($limit)
            ->get();
    }

    public function toExportArray(): array
    {
        return [
            'id' => $this->id,
            'stem_ar' => $this->stem_ar,
            'latex' => $this->latex,
            'item_type' => $this->item_type,
            'difficulty' => $this->difficulty,
            'difficulty_level' => $this->difficulty_level,
            'concepts' => $this->concepts->map(fn($c) => [
                'code' => $c->code,
                'name_ar' => $c->name_ar,
                'grade' => $c->grade,
                'strand' => $c->strand,
            ]),
            'tags' => $this->tags->map(fn($t) => [
                'code' => $t->code,
                'name_ar' => $t->name_ar,
            ]),
            'options' => $this->options->map(fn($o) => [
                'text_ar' => $o->text_ar,
                'latex' => $o->latex,
                'is_correct' => $o->is_correct,
                'order_index' => $o->order_index,
            ]),
            'hints' => $this->hints->map(fn($h) => [
                'text_ar' => $h->text_ar,
                'latex' => $h->latex,
                'order_index' => $h->order_index,
            ]),
            'solutions' => $this->solutions->map(fn($s) => [
                'text_ar' => $s->text_ar,
                'latex' => $s->latex,
                'solution_type' => $s->solution_type,
            ]),
            'published_at' => $this->published_at?->toISOString(),
        ];
    }

    // Static Methods
    public static function getTypeOptions(): array
    {
        return [
            'multiple_choice' => 'Multiple Choice',
            'short_answer' => 'Short Answer',
            'essay' => 'Essay',
            'numerical' => 'Numerical',
            'matching' => 'Matching',
            'true_false' => 'True/False',
        ];
    }

    public static function getDifficultyLevels(): array
    {
        return [
            'easy' => ['min' => 1.0, 'max' => 2.0],
            'medium' => ['min' => 2.1, 'max' => 3.5],
            'hard' => ['min' => 3.6, 'max' => 5.0],
        ];
    }

    public static function getContentStats(): array
    {
        return [
            'total_items' => static::count(),
            'by_type' => static::selectRaw('item_type, COUNT(*) as count')
                ->groupBy('item_type')
                ->pluck('count', 'item_type'),
            'by_difficulty' => static::selectRaw('
                    CASE
                        WHEN difficulty <= 2 THEN "Easy"
                        WHEN difficulty <= 3.5 THEN "Medium"
                        ELSE "Hard"
                    END as level,
                    COUNT(*) as count
                ')
                ->groupBy('level')
                ->pluck('count', 'level'),
            'avg_difficulty' => static::avg('difficulty'),
            'with_solutions' => static::has('solutions')->count(),
            'with_hints' => static::has('hints')->count(),
        ];
    }
}
