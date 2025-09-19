<?php
// app/Models/ItemSolution.php
namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;

class ItemSolution extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'itemable_id',
        'itemable_type',
        'text_ar',
        'latex',
        'solution_type',
    ];

    protected $appends = [
        'has_latex',
        'display_text',
        'word_count',
        'step_count',
        'complexity_level',
    ];

    public const SOLUTION_TYPES = [
        'worked' => 'Worked Solution',
        'brief' => 'Brief Answer',
        'alternative' => 'Alternative Method',
        'explanation' => 'Explanation Only',
        'numerical' => 'Numerical Answer',
    ];

    // Relationships
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('solution_type', $type);
    }

    public function scopeWorkedSolutions(Builder $query): Builder
    {
        return $query->where('solution_type', 'worked');
    }

    public function scopeBriefAnswers(Builder $query): Builder
    {
        return $query->where('solution_type', 'brief');
    }

    public function scopeWithLatex(Builder $query): Builder
    {
        return $query->whereNotNull('latex');
    }

    public function scopeForItem(Builder $query, string $itemType, string $itemId): Builder
    {
        return $query->where('itemable_type', $itemType)
            ->where('itemable_id', $itemId);
    }

    // Accessors
    public function getHasLatexAttribute(): bool
    {
        return !empty($this->latex);
    }

    public function getDisplayTextAttribute(): string
    {
        $typeLabel = self::SOLUTION_TYPES[$this->solution_type] ?? 'Solution';
        return "{$typeLabel}: " . $this->text_ar . ($this->has_latex ? ' [Math]' : '');
    }

    public function getWordCountAttribute(): int
    {
        return str_word_count(strip_tags($this->text_ar));
    }

    public function getStepCountAttribute(): int
    {
        // Count solution steps based on common Arabic step indicators
        $stepIndicators = [
            'الخطوة',
            'أولاً',
            'ثانياً',
            'ثالثاً',
            'رابعاً',
            'خامساً',
            'أخيراً',
            'بالتالي',
            'إذن',
            '1-',
            '2-',
            '3-',
            '4-',
            '5-',
        ];

        $stepCount = 0;
        foreach ($stepIndicators as $indicator) {
            $stepCount += substr_count($this->text_ar, $indicator);
        }

        // If no explicit steps found, estimate based on sentence count
        if ($stepCount === 0) {
            $sentences = explode('،', $this->text_ar);
            $sentences = array_merge($sentences, explode('؟', implode('', $sentences)));
            $sentences = array_merge($sentences, explode('!', implode('', $sentences)));
            $sentences = array_merge($sentences, explode('。', implode('', $sentences)));
            $stepCount = max(1, count(array_filter($sentences, 'trim')));
        }

        return max(1, $stepCount);
    }

    public function getComplexityLevelAttribute(): string
    {
        $score = 0;

        // Word count contributes to complexity
        $wordCount = $this->word_count;
        if ($wordCount > 100) $score += 3;
        elseif ($wordCount > 50) $score += 2;
        elseif ($wordCount > 20) $score += 1;

        // Step count
        $stepCount = $this->step_count;
        if ($stepCount > 5) $score += 3;
        elseif ($stepCount > 3) $score += 2;
        elseif ($stepCount > 1) $score += 1;

        // LaTeX indicates mathematical complexity
        if ($this->has_latex) $score += 2;

        // Solution type affects complexity
        $score += match($this->solution_type) {
            'worked' => 3,
            'alternative' => 2,
            'explanation' => 2,
            'brief' => 1,
            'numerical' => 0,
            default => 1,
        };

        return match(true) {
            $score >= 8 => 'high',
            $score >= 5 => 'medium',
            default => 'low',
        };
    }

    // Helper Methods
    public function isWorkedSolution(): bool
    {
        return $this->solution_type === 'worked';
    }

    public function isBriefAnswer(): bool
    {
        return $this->solution_type === 'brief';
    }

    public function isAlternativeMethod(): bool
    {
        return $this->solution_type === 'alternative';
    }

    public function isExplanationOnly(): bool
    {
        return $this->solution_type === 'explanation';
    }

    public function isNumericalAnswer(): bool
    {
        return $this->solution_type === 'numerical';
    }

    public function getSiblings(): Collection
    {
        return static::where('itemable_id', $this->itemable_id)
            ->where('itemable_type', $this->itemable_type)
            ->where('id', '!=', $this->id)
            ->get();
    }

    public function getAlternativeSolutions(): Collection
    {
        return $this->getSiblings()->where('solution_type', '!=', $this->solution_type);
    }

    public function extractSteps(): array
    {
        $text = $this->text_ar;
        $steps = [];

        // Try to split by common step separators
        $separators = ['الخطوة', 'أولاً', 'ثانياً', 'ثالثاً', 'رابعاً', 'خامساً'];

        foreach ($separators as $separator) {
            if (str_contains($text, $separator)) {
                $parts = explode($separator, $text);
                foreach ($parts as $i => $part) {
                    if ($i === 0 && empty(trim($part))) continue;
                    $stepText = trim($part);
                    if (!empty($stepText)) {
                        $steps[] = [
                            'step_number' => count($steps) + 1,
                            'text' => $stepText,
                            'separator_used' => $separator,
                        ];
                    }
                }
                break;
            }
        }

        // If no steps found, treat entire text as single step
        if (empty($steps)) {
            $steps[] = [
                'step_number' => 1,
                'text' => $text,
                'separator_used' => null,
            ];
        }

        return $steps;
    }

    public function hasNumericalAnswer(): bool
    {
        // Check if solution contains numerical values
        return preg_match('/\d+/', $this->text_ar) === 1;
    }

    public function extractNumericalAnswers(): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', $this->text_ar, $matches);
        return $matches[0] ?? [];
    }

    public function getQualityScore(): float
    {
        $score = 0;
        $maxScore = 100;

        // Content quality (40 points)
        $wordCount = $this->word_count;
        if ($wordCount >= 30) $score += 40;
        elseif ($wordCount >= 15) $score += 30;
        elseif ($wordCount >= 5) $score += 20;
        else $score += 10;

        // Step-by-step structure (25 points)
        $stepCount = $this->step_count;
        if ($stepCount >= 3) $score += 25;
        elseif ($stepCount >= 2) $score += 20;
        else $score += 15;

        // Mathematical notation (20 points)
        if ($this->has_latex) $score += 20;

        // Solution type appropriateness (15 points)
        $score += match($this->solution_type) {
            'worked' => 15,
            'explanation' => 12,
            'alternative' => 10,
            'brief' => 8,
            'numerical' => 5,
            default => 5,
        };

        return round(($score / $maxScore) * 100, 1);
    }

    // Validation Methods
    public function validateContent(): array
    {
        $errors = [];

        if (empty(trim($this->text_ar))) {
            $errors[] = 'Solution text cannot be empty';
        }

        if (strlen($this->text_ar) < 10) {
            $errors[] = 'Solution text too short (minimum 10 characters)';
        }

        if (strlen($this->text_ar) > 5000) {
            $errors[] = 'Solution text too long (maximum 5000 characters)';
        }

        if (!in_array($this->solution_type, array_keys(self::SOLUTION_TYPES))) {
            $errors[] = 'Invalid solution type';
        }

        // Type-specific validations
        if ($this->solution_type === 'worked' && $this->step_count < 2) {
            $errors[] = 'Worked solutions should have multiple steps';
        }

        if ($this->solution_type === 'numerical' && !$this->hasNumericalAnswer()) {
            $errors[] = 'Numerical solutions must contain numerical values';
        }

        return $errors;
    }

    public function isValid(): bool
    {
        return empty($this->validateContent());
    }

    // Static Methods
    public static function getSolutionTypeOptions(): array
    {
        return self::SOLUTION_TYPES;
    }

    public static function createForItem(string $itemType, string $itemId, array $solutionData): self
    {
        return static::create([
            'itemable_type' => $itemType,
            'itemable_id' => $itemId,
            'text_ar' => $solutionData['text_ar'],
            'latex' => $solutionData['latex'] ?? null,
            'solution_type' => $solutionData['solution_type'] ?? 'worked',
        ]);
    }

    public static function getStatsForItem(string $itemType, string $itemId): array
    {
        $solutions = static::where('itemable_type', $itemType)
            ->where('itemable_id', $itemId)
            ->get();

        return [
            'total_solutions' => $solutions->count(),
            'by_type' => $solutions->groupBy('solution_type')->map->count(),
            'with_latex' => $solutions->where('has_latex', true)->count(),
            'avg_word_count' => $solutions->avg('word_count'),
            'avg_step_count' => $solutions->avg('step_count'),
            'complexity_distribution' => $solutions->groupBy('complexity_level')->map->count(),
            'avg_quality_score' => $solutions->avg(function($solution) {
                return $solution->getQualityScore();
            }),
        ];
    }

    public static function generateTemplate(string $itemType, string $difficulty): array
    {
        $templates = [
            'worked' => [
                'المعطيات: [اذكر المعطيات]',
                'المطلوب: [حدد المطلوب]',
                'الحل:',
                'الخطوة الأولى: [شرح الخطوة]',
                'الخطوة الثانية: [شرح الخطوة]',
                'النتيجة النهائية: [الإجابة]',
            ],
            'brief' => [
                'الحل: [إجابة مختصرة]',
            ],
            'explanation' => [
                'التفسير: [شرح المفهوم أو السبب]',
            ],
        ];

        return $templates['worked']; // Default to worked solution
    }
}
