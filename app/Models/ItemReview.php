<?php
// app/Models/ItemReview.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ItemReview extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'item_draft_id',
        'reviewer_id',
        'status',
        'feedback',
        'rubric_scores',
        'overall_score',
    ];

    protected $casts = [
        'rubric_scores' => 'array',
        'overall_score' => 'float',
    ];

    protected $appends = [
        'status_label',
        'has_feedback',
        'review_age_days',
        'quality_rating',
    ];

    public const REVIEW_STATUSES = [
        'pending' => 'Pending Review',
        'approved' => 'Approved',
        'changes_requested' => 'Changes Requested',
        'rejected' => 'Rejected',
    ];

    public const QUALITY_CRITERIA = [
        'clarity' => 'وضوح السؤال',
        'accuracy' => 'دقة المحتوى',
        'difficulty' => 'مناسبة مستوى الصعوبة',
        'language' => 'جودة اللغة',
        'completeness' => 'اكتمال السؤال',
    ];

    // Relationships
    public function itemDraft(): BelongsTo
    {
        return $this->belongsTo(ItemDraft::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    // Scopes
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    public function scopeChangesRequested(Builder $query): Builder
    {
        return $query->where('status', 'changes_requested');
    }

    public function scopeByReviewer(Builder $query, string $reviewerId): Builder
    {
        return $query->where('reviewer_id', $reviewerId);
    }

    public function scopeHighQuality(Builder $query, float $minScore = 4.0): Builder
    {
        return $query->where('overall_score', '>=', $minScore);
    }

    public function scopeLowQuality(Builder $query, float $maxScore = 2.5): Builder
    {
        return $query->where('overall_score', '<=', $maxScore);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // Accessors
    public function getStatusLabelAttribute(): string
    {
        return self::REVIEW_STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function getHasFeedbackAttribute(): bool
    {
        return !empty(trim($this->feedback));
    }

    public function getReviewAgeDaysAttribute(): int
    {
        return $this->created_at->diffInDays(now());
    }

    public function getQualityRatingAttribute(): string
    {
        if (!$this->overall_score) {
            return 'unrated';
        }

        return match(true) {
            $this->overall_score >= 4.5 => 'excellent',
            $this->overall_score >= 4.0 => 'good',
            $this->overall_score >= 3.0 => 'satisfactory',
            $this->overall_score >= 2.0 => 'needs_improvement',
            default => 'poor',
        };
    }

    // Helper Methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function hasChangesRequested(): bool
    {
        return $this->status === 'changes_requested';
    }

    public function isPositive(): bool
    {
        return $this->isApproved();
    }

    public function isNegative(): bool
    {
        return $this->isRejected();
    }

    public function requiresAction(): bool
    {
        return $this->hasChangesRequested();
    }

    public function getRubricScore(string $criterion): ?float
    {
        return $this->rubric_scores[$criterion] ?? null;
    }

    public function getAllRubricScores(): array
    {
        return $this->rubric_scores ?? [];
    }

    public function hasRubricScores(): bool
    {
        return !empty($this->rubric_scores);
    }

    public function getWeightedScore(): float
    {
        if (!$this->hasRubricScores()) {
            return $this->overall_score ?? 0;
        }

        $weights = [
            'clarity' => 0.25,
            'accuracy' => 0.30,
            'difficulty' => 0.20,
            'language' => 0.15,
            'completeness' => 0.10,
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($this->rubric_scores as $criterion => $score) {
            $weight = $weights[$criterion] ?? 0.2; // Default weight
            $totalScore += $score * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($totalScore / $totalWeight, 1) : 0;
    }

    public function getScoreBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->rubric_scores ?? [] as $criterion => $score) {
            $breakdown[$criterion] = [
                'score' => $score,
                'label' => self::QUALITY_CRITERIA[$criterion] ?? $criterion,
                'percentage' => ($score / 5) * 100,
                'rating' => $this->getScoreRating($score),
            ];
        }

        return $breakdown;
    }

    protected function getScoreRating(float $score): string
    {
        return match(true) {
            $score >= 4.5 => 'ممتاز',
            $score >= 4.0 => 'جيد جداً',
            $score >= 3.0 => 'جيد',
            $score >= 2.0 => 'مقبول',
            default => 'ضعيف',
        };
    }

    public function getReviewSummary(): array
    {
        return [
            'reviewer_name' => $this->reviewer->name ?? 'Unknown',
            'status' => $this->status_label,
            'overall_score' => $this->overall_score,
            'quality_rating' => $this->quality_rating,
            'has_feedback' => $this->has_feedback,
            'feedback_length' => strlen($this->feedback ?? ''),
            'rubric_completion' => $this->hasRubricScores() ?
                (count($this->rubric_scores) / count(self::QUALITY_CRITERIA)) * 100 : 0,
            'review_age_days' => $this->review_age_days,
            'is_actionable' => $this->requiresAction(),
        ];
    }

    public function canBeUpdated(): bool
    {
        // Reviews can be updated within 24 hours or if changes are requested
        return $this->created_at->diffInHours(now()) <= 24
            || $this->hasChangesRequested();
    }

    public function getActionableItems(): array
    {
        if (!$this->requiresAction() || !$this->has_feedback) {
            return [];
        }

        // Parse feedback for actionable items
        $feedback = $this->feedback;
        $actions = [];

        // Look for common action words/phrases in Arabic
        $actionPatterns = [
            'يجب' => 'required',
            'ينبغي' => 'suggested',
            'من الأفضل' => 'recommended',
            'تحتاج إلى' => 'needs',
            'عدل' => 'modify',
            'أضف' => 'add',
            'احذف' => 'remove',
            'صحح' => 'correct',
        ];

        foreach ($actionPatterns as $pattern => $type) {
            if (str_contains($feedback, $pattern)) {
                $actions[] = [
                    'type' => $type,
                    'indicator' => $pattern,
                    'priority' => $type === 'required' ? 'high' : 'medium',
                ];
            }
        }

        return $actions;
    }

    // Static Methods
    public static function getStatusOptions(): array
    {
        return self::REVIEW_STATUSES;
    }

    public static function getQualityCriteria(): array
    {
        return self::QUALITY_CRITERIA;
    }

    public static function getReviewerStats(string $reviewerId): array
    {
        $reviews = static::where('reviewer_id', $reviewerId)->get();

        return [
            'total_reviews' => $reviews->count(),
            'reviews_by_status' => $reviews->groupBy('status')->map->count(),
            'avg_score' => $reviews->whereNotNull('overall_score')->avg('overall_score'),
            'reviews_with_feedback' => $reviews->where('has_feedback', true)->count(),
            'avg_review_age' => $reviews->avg('review_age_days'),
            'quality_distribution' => $reviews->groupBy('quality_rating')->map->count(),
            'recent_activity' => $reviews->where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }

    public static function getItemStats(string $itemDraftId): array
    {
        $reviews = static::where('item_draft_id', $itemDraftId)->get();

        return [
            'total_reviews' => $reviews->count(),
            'latest_status' => $reviews->sortByDesc('created_at')->first()?->status,
            'avg_score' => $reviews->whereNotNull('overall_score')->avg('overall_score'),
            'consensus_level' => static::calculateConsensus($reviews),
            'review_history' => $reviews->sortBy('created_at')->values(),
        ];
    }

    protected static function calculateConsensus(Collection $reviews): float
    {
        if ($reviews->count() < 2) {
            return 100; // Perfect consensus with single review
        }

        $scores = $reviews->whereNotNull('overall_score')->pluck('overall_score');
        if ($scores->isEmpty()) {
            return 0;
        }

        $mean = $scores->avg();
        $variance = $scores->reduce(function ($carry, $score) use ($mean) {
                return $carry + pow($score - $mean, 2);
            }, 0) / $scores->count();

        $standardDeviation = sqrt($variance);

        // Convert to consensus percentage (lower std dev = higher consensus)
        return max(0, 100 - ($standardDeviation * 40));
    }

    public static function getSystemStats(): array
    {
        return [
            'total_reviews' => static::count(),
            'pending_reviews' => static::pending()->count(),
            'approval_rate' => static::approved()->count() / max(1, static::count()) * 100,
            'avg_review_score' => static::whereNotNull('overall_score')->avg('overall_score'),
            'reviews_by_quality' => static::whereNotNull('overall_score')
                ->selectRaw('
                    CASE
                        WHEN overall_score >= 4.5 THEN "excellent"
                        WHEN overall_score >= 4.0 THEN "good"
                        WHEN overall_score >= 3.0 THEN "satisfactory"
                        WHEN overall_score >= 2.0 THEN "needs_improvement"
                        ELSE "poor"
                    END as quality,
                    COUNT(*) as count
                ')
                ->groupBy('quality')
                ->pluck('count', 'quality'),
            'active_reviewers' => static::distinct('reviewer_id')
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
        ];
    }
}
