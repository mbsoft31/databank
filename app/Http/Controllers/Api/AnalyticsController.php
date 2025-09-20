<?php
// app/Http/Controllers/Api/AnalyticsController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{ItemDraft, ItemProd, ItemReview, Export, User, AuditLog};
use App\Enums\{ItemStatus, ExportStatus};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};

class AnalyticsController extends Controller
{
    public function __construct()
    {
        /*$this->middleware(['auth:sanctum', 'can:admin-access']);
        $this->middleware('throttle:60,1'); // 60 requests per minute*/
    }

    /**
     * System overview dashboard
     */
    public function overview(Request $request)
    {
        $request->validate([
            'period' => 'nullable|string|in:7d,30d,90d,1y',
        ]);

        $period = $request->get('period', '30d');
        $cacheKey = "analytics_overview_{$period}";

        $data = Cache::remember($cacheKey, 300, function() use ($period) {
            $dateRange = $this->getDateRange($period);

            return [
                'totals' => [
                    'item_drafts' => ItemDraft::count(),
                    'published_items' => ItemProd::count(),
                    'active_users' => User::whereDate('updated_at', '>=', $dateRange[0])->count(),
                    'pending_reviews' => ItemDraft::where('status', ItemStatus::InReview)->count(),
                    'completed_exports' => Export::where('status', ExportStatus::Completed)->count(),
                ],
                'growth' => [
                    'new_drafts' => ItemDraft::whereBetween('created_at', $dateRange)->count(),
                    'new_publications' => ItemProd::whereBetween('published_at', $dateRange)->count(),
                    'new_users' => User::whereBetween('created_at', $dateRange)->count(),
                    'completed_reviews' => ItemReview::whereBetween('created_at', $dateRange)->count(),
                ],
                'quality_metrics' => [
                    'avg_review_score' => ItemReview::whereNotNull('overall_score')
                        ->whereBetween('created_at', $dateRange)
                        ->avg('overall_score'),
                    'approval_rate' => $this->getApprovalRate($dateRange),
                    'publication_rate' => $this->getPublicationRate($dateRange),
                    'avg_review_time' => $this->getAverageReviewTime($dateRange),
                ],
                'content_distribution' => [
                    'by_type' => ItemProd::selectRaw('item_type, COUNT(*) as count')
                        ->groupBy('item_type')
                        ->pluck('count', 'item_type'),
                    'by_difficulty' => ItemProd::selectRaw('
                            CASE
                                WHEN difficulty <= 2 THEN "Easy"
                                WHEN difficulty <= 3.5 THEN "Medium"
                                ELSE "Hard"
                            END as difficulty_level,
                            COUNT(*) as count
                        ')
                        ->groupBy('difficulty_level')
                        ->pluck('count', 'difficulty_level'),
                    'by_grade' => DB::table('item_prod')
                        ->join('item_prod_concepts', 'item_prod.id', '=', 'item_prod_concepts.item_prod_id')
                        ->join('concepts', 'item_prod_concepts.concept_id', '=', 'concepts.id')
                        ->selectRaw('concepts.grade, COUNT(DISTINCT item_prod.id) as count')
                        ->whereNotNull('concepts.grade')
                        ->groupBy('concepts.grade')
                        ->pluck('count', 'grade'),
                ],
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Authoring trends and patterns
     */
    public function authoringTrends(Request $request)
    {
        $request->validate([
            'period' => 'nullable|string|in:7d,30d,90d,1y',
            'granularity' => 'nullable|string|in:day,week,month',
        ]);

        $period = $request->get('period', '30d');
        $granularity = $request->get('granularity', 'day');

        $cacheKey = "analytics_authoring_{$period}_{$granularity}";

        $data = Cache::remember($cacheKey, 600, function() use ($period, $granularity) {
            $dateRange = $this->getDateRange($period);
            $dateFormat = $this->getDateFormat($granularity);

            return [
                'creation_timeline' => ItemDraft::selectRaw("
                        {$dateFormat} as period,
                        COUNT(*) as total_created,
                        COUNT(CASE WHEN status = 'published' THEN 1 END) as published,
                        COUNT(CASE WHEN status = 'archived' THEN 1 END) as archived
                    ")
                    ->whereBetween('created_at', $dateRange)
                    ->groupBy('period')
                    ->orderBy('period')
                    ->get(),

                'top_authors' => DB::table('item_drafts')
                    ->join('users', 'item_drafts.created_by', '=', 'users.id')
                    ->selectRaw('
                        users.name,
                        users.id,
                        COUNT(*) as total_items,
                        COUNT(CASE WHEN item_drafts.status = "published" THEN 1 END) as published_items,
                        AVG(CASE WHEN item_drafts.status = "published" THEN
                            DATEDIFF(item_drafts.updated_at, item_drafts.created_at)
                        END) as avg_days_to_publish
                    ')
                    ->whereBetween('item_drafts.created_at', $dateRange)
                    ->groupBy('users.id', 'users.name')
                    ->orderBy('total_items', 'desc')
                    ->limit(10)
                    ->get(),

                'productivity_patterns' => [
                    'by_hour' => ItemDraft::selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
                        ->whereBetween('created_at', $dateRange)
                        ->groupBy('hour')
                        ->orderBy('hour')
                        ->pluck('count', 'hour'),

                    'by_day_of_week' => ItemDraft::selectRaw('DAYNAME(created_at) as day, COUNT(*) as count')
                        ->whereBetween('created_at', $dateRange)
                        ->groupBy('day')
                        ->pluck('count', 'day'),
                ],

                'content_complexity' => [
                    'avg_options_per_item' => DB::table('item_options')
                        ->join('item_drafts', 'item_options.itemable_id', '=', 'item_drafts.id')
                        ->where('item_options.itemable_type', 'App\\Models\\ItemDraft')
                        ->whereBetween('item_drafts.created_at', $dateRange)
                        ->selectRaw('AVG(option_count) as avg_options')
                        ->fromSub(function($query) {
                            $query->selectRaw('itemable_id, COUNT(*) as option_count')
                                ->from('item_options')
                                ->where('itemable_type', 'App\\Models\\ItemDraft')
                                ->groupBy('itemable_id');
                        }, 'option_counts')
                        ->value('avg_options'),

                    'items_with_hints' => ItemDraft::whereHas('hints')
                        ->whereBetween('created_at', $dateRange)
                        ->count(),

                    'items_with_solutions' => ItemDraft::whereHas('solutions')
                        ->whereBetween('created_at', $dateRange)
                        ->count(),
                ],

                'revision_patterns' => AuditLog::selectRaw('
                        auditable_id,
                        COUNT(*) as revision_count,
                        DATEDIFF(MAX(created_at), MIN(created_at)) as revision_span_days
                    ')
                    ->where('auditable_type', 'App\\Models\\ItemDraft')
                    ->where('action', 'updated')
                    ->whereBetween('created_at', $dateRange)
                    ->groupBy('auditable_id')
                    ->havingRaw('revision_count > 1')
                    ->get()
                    ->pipe(function($revisions) {
                        return [
                            'avg_revisions_per_item' => $revisions->avg('revision_count'),
                            'avg_revision_span_days' => $revisions->avg('revision_span_days'),
                            'most_revised_items' => $revisions->sortByDesc('revision_count')->take(5),
                        ];
                    }),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Review system metrics
     */
    public function reviewMetrics(Request $request)
    {
        $request->validate([
            'period' => 'nullable|string|in:7d,30d,90d,1y',
            'reviewer_id' => 'nullable|uuid|exists:users,id',
        ]);

        $period = $request->get('period', '30d');
        $reviewerId = $request->get('reviewer_id');

        $cacheKey = "analytics_reviews_{$period}" . ($reviewerId ? "_{$reviewerId}" : '');

        $data = Cache::remember($cacheKey, 300, function() use ($period, $reviewerId) {
            $dateRange = $this->getDateRange($period);

            $baseQuery = ItemReview::whereBetween('created_at', $dateRange);
            if ($reviewerId) {
                $baseQuery->where('reviewer_id', $reviewerId);
            }

            return [
                'review_summary' => [
                    'total_reviews' => $baseQuery->count(),
                    'avg_score' => $baseQuery->whereNotNull('overall_score')->avg('overall_score'),
                    'reviews_by_status' => $baseQuery->selectRaw('status, COUNT(*) as count')
                        ->groupBy('status')
                        ->pluck('count', 'status'),
                ],

                'reviewer_performance' => User::join('item_reviews', 'users.id', '=', 'item_reviews.reviewer_id')
                    ->whereBetween('item_reviews.created_at', $dateRange)
                    ->when($reviewerId, fn($q) => $q->where('users.id', $reviewerId))
                    ->selectRaw('
                        users.id,
                        users.name,
                        COUNT(*) as total_reviews,
                        AVG(item_reviews.overall_score) as avg_score_given,
                        COUNT(CASE WHEN item_reviews.status = "approved" THEN 1 END) as approved_count,
                        COUNT(CASE WHEN item_reviews.status = "changes_requested" THEN 1 END) as changes_requested_count,
                        COUNT(CASE WHEN item_reviews.status = "rejected" THEN 1 END) as rejected_count
                    ')
                    ->groupBy('users.id', 'users.name')
                    ->orderBy('total_reviews', 'desc')
                    ->limit(10)
                    ->get(),

                'quality_trends' => ItemReview::selectRaw('
                        DATE(created_at) as date,
                        AVG(overall_score) as avg_score,
                        COUNT(*) as review_count
                    ')
                    ->whereNotNull('overall_score')
                    ->whereBetween('created_at', $dateRange)
                    ->when($reviewerId, fn($q) => $q->where('reviewer_id', $reviewerId))
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get(),

                'rubric_analysis' => [
                    'criteria_averages' => $this->getCriteriaAverages($dateRange, $reviewerId),
                    'score_distribution' => ItemReview::selectRaw('
                            CASE
                                WHEN overall_score < 2 THEN "Poor (1-2)"
                                WHEN overall_score < 3 THEN "Below Average (2-3)"
                                WHEN overall_score < 4 THEN "Average (3-4)"
                                WHEN overall_score < 5 THEN "Good (4-5)"
                                ELSE "Excellent (5)"
                            END as score_range,
                            COUNT(*) as count
                        ')
                        ->whereNotNull('overall_score')
                        ->whereBetween('created_at', $dateRange)
                        ->when($reviewerId, fn($q) => $q->where('reviewer_id', $reviewerId))
                        ->groupBy('score_range')
                        ->pluck('count', 'score_range'),
                ],

                'bottlenecks' => [
                    'items_pending_review' => ItemDraft::where('status', ItemStatus::InReview)
                        ->selectRaw('
                            id,
                            stem_ar,
                            created_at,
                            DATEDIFF(NOW(), created_at) as days_pending
                        ')
                        ->orderBy('days_pending', 'desc')
                        ->limit(10)
                        ->get(),

                    'avg_review_time_by_type' => DB::table('item_reviews')
                        ->join('item_drafts', 'item_reviews.item_draft_id', '=', 'item_drafts.id')
                        ->selectRaw('
                            item_drafts.item_type,
                            AVG(DATEDIFF(item_reviews.created_at, item_drafts.updated_at)) as avg_review_days
                        ')
                        ->whereBetween('item_reviews.created_at', $dateRange)
                        ->groupBy('item_drafts.item_type')
                        ->pluck('avg_review_days', 'item_type'),
                ],
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Content and usage statistics
     */
    public function contentStats(Request $request)
    {
        $request->validate([
            'breakdown' => 'nullable|string|in:grade,strand,type,difficulty',
        ]);

        $breakdown = $request->get('breakdown', 'grade');
        $cacheKey = "analytics_content_stats_{$breakdown}";

        $data = Cache::remember($cacheKey, 900, function() use ($breakdown) {
            return [
                'content_distribution' => $this->getContentDistribution($breakdown),

                'concept_coverage' => [
                    'total_concepts' => \App\Models\Concept::count(),
                    'concepts_with_items' => \App\Models\Concept::has('itemProds')->count(),
                    'most_used_concepts' => \App\Models\Concept::withCount('itemProds')
                        ->orderBy('item_prods_count', 'desc')
                        ->limit(10)
                        ->get(['id', 'name_ar', 'grade', 'strand', 'item_prods_count']),
                    'underutilized_concepts' => \App\Models\Concept::withCount('itemProds')
                        ->having('item_prods_count', '<=', 2)
                        ->orderBy('item_prods_count', 'asc')
                        ->limit(10)
                        ->get(['id', 'name_ar', 'grade', 'strand', 'item_prods_count']),
                ],

                'export_usage' => [
                    'total_exports' => Export::count(),
                    'exports_by_format' => Export::selectRaw('kind, COUNT(*) as count')
                        ->groupBy('kind')
                        ->pluck('count', 'kind'),
                    'most_exported_items' => DB::table('exports')
                        ->where('status', 'completed')
                        ->get(['params'])
                        ->flatMap(function($export) {
                            $params = json_decode($export->params, true);
                            return $params['item_ids'] ?? [];
                        })
                        ->countBy()
                        ->sortDesc()
                        ->take(10),
                ],

                'search_patterns' => Cache::get('search_analytics', [
                    'note' => 'Search analytics would be collected from search requests'
                ]),

                'media_usage' => [
                    'total_media_files' => \App\Models\MediaAsset::count(),
                    'total_storage_mb' => \App\Models\MediaAsset::sum('size_bytes') / 1048576,
                    'media_by_type' => \App\Models\MediaAsset::selectRaw('
                            CASE
                                WHEN mime_type LIKE "image/%" THEN "Images"
                                WHEN mime_type LIKE "video/%" THEN "Videos"
                                WHEN mime_type LIKE "audio/%" THEN "Audio"
                                ELSE "Other"
                            END as media_type,
                            COUNT(*) as count,
                            SUM(size_bytes) as total_size
                        ')
                        ->groupBy('media_type')
                        ->get(),
                ],

                'system_health' => [
                    'duplicate_content' => \App\Models\ContentHash::selectRaw('content_hash, COUNT(*) as duplicates')
                        ->groupBy('content_hash')
                        ->having('duplicates', '>', 1)
                        ->count(),
                    'orphaned_media' => \App\Models\MediaAsset::whereDoesntHave('itemDrafts')
                        ->whereDoesntHave('itemProds')
                        ->count(),
                    'items_without_solutions' => ItemProd::doesntHave('solutions')->count(),
                    'items_without_concepts' => ItemProd::doesntHave('concepts')->count(),
                ],
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * User activity and engagement
     */
    public function userActivity(Request $request)
    {
        $request->validate([
            'period' => 'nullable|string|in:7d,30d,90d,1y',
            'user_id' => 'nullable|uuid|exists:users,id',
        ]);

        $period = $request->get('period', '30d');
        $userId = $request->get('user_id');
        $dateRange = $this->getDateRange($period);

        $data = [
            'user_summary' => [
                'total_users' => User::count(),
                'active_users' => User::whereDate('updated_at', '>=', $dateRange[0])->count(),
                'users_by_role' => User::selectRaw('role, COUNT(*) as count')
                    ->groupBy('role')
                    ->pluck('count', 'role'),
            ],

            'activity_timeline' => AuditLog::selectRaw('
                    DATE(created_at) as date,
                    COUNT(DISTINCT user_id) as active_users,
                    COUNT(*) as total_actions
                ')
                ->whereBetween('created_at', $dateRange)
                ->when($userId, fn($q) => $q->where('user_id', $userId))
                ->groupBy('date')
                ->orderBy('date')
                ->get(),

            'top_contributors' => User::leftJoin('item_drafts', 'users.id', '=', 'item_drafts.created_by')
                ->leftJoin('item_reviews', 'users.id', '=', 'item_reviews.reviewer_id')
                ->selectRaw('
                    users.id,
                    users.name,
                    users.role,
                    COUNT(DISTINCT item_drafts.id) as items_created,
                    COUNT(DISTINCT item_reviews.id) as reviews_completed,
                    users.updated_at as last_active
                ')
                ->groupBy('users.id', 'users.name', 'users.role', 'users.updated_at')
                ->orderByDesc('items_created')
                ->orderByDesc('reviews_completed')
                ->limit(20)
                ->get(),

            'engagement_metrics' => [
                'avg_session_actions' => AuditLog::selectRaw('
                        user_id,
                        DATE(created_at) as session_date,
                        COUNT(*) as actions_per_session
                    ')
                    ->whereBetween('created_at', $dateRange)
                    ->groupBy('user_id', 'session_date')
                    ->get()
                    ->avg('actions_per_session'),

                'retention_rate' => $this->calculateRetentionRate($dateRange),

                'feature_usage' => AuditLog::selectRaw('action, COUNT(*) as usage_count')
                    ->whereBetween('created_at', $dateRange)
                    ->groupBy('action')
                    ->orderBy('usage_count', 'desc')
                    ->limit(10)
                    ->pluck('usage_count', 'action'),
            ],
        ];

        return response()->json(['data' => $data]);
    }

    /**
     * Export custom analytics report
     */
    public function exportReport(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:overview,authoring,reviews,content,users',
            'period' => 'nullable|string|in:7d,30d,90d,1y',
            'format' => 'nullable|string|in:json,csv,pdf',
        ]);

        // This would generate and return a comprehensive report
        // Implementation would depend on specific reporting requirements

        return response()->json([
            'message' => 'Analytics report generation queued',
            'report_id' => \Str::uuid(),
            'estimated_completion' => now()->addMinutes(5)->toISOString(),
        ]);
    }

    // Helper methods

    private function getDateRange(string $period): array
    {
        return match($period) {
            '7d' => [now()->subDays(7), now()],
            '30d' => [now()->subDays(30), now()],
            '90d' => [now()->subDays(90), now()],
            '1y' => [now()->subYear(), now()],
            default => [now()->subDays(30), now()],
        };
    }

    private function getDateFormat(string $granularity): string
    {
        return match($granularity) {
            'day' => 'DATE(created_at)',
            'week' => 'YEARWEEK(created_at)',
            'month' => 'DATE_FORMAT(created_at, "%Y-%m")',
            default => 'DATE(created_at)',
        };
    }

    private function getApprovalRate(array $dateRange): float
    {
        $totalReviews = ItemReview::whereBetween('created_at', $dateRange)->count();
        $approvedReviews = ItemReview::whereBetween('created_at', $dateRange)
            ->where('status', 'approved')
            ->count();

        return $totalReviews > 0 ? ($approvedReviews / $totalReviews) * 100 : 0;
    }

    private function getPublicationRate(array $dateRange): float
    {
        $totalDrafts = ItemDraft::whereBetween('created_at', $dateRange)->count();
        $publishedDrafts = ItemDraft::whereBetween('created_at', $dateRange)
            ->where('status', ItemStatus::Published)
            ->count();

        return $totalDrafts > 0 ? ($publishedDrafts / $totalDrafts) * 100 : 0;
    }

    private function getAverageReviewTime(array $dateRange): float
    {
        return DB::table('item_reviews')
            ->join('item_drafts', 'item_reviews.item_draft_id', '=', 'item_drafts.id')
            ->whereBetween('item_reviews.created_at', $dateRange)
            ->selectRaw('AVG(DATEDIFF(item_reviews.created_at, item_drafts.updated_at)) as avg_days')
            ->value('avg_days') ?? 0;
    }

    private function getContentDistribution(string $breakdown): array
    {
        $query = ItemProd::query();

        return match($breakdown) {
            'grade' => $query->join('item_prod_concepts', 'item_prod.id', '=', 'item_prod_concepts.item_prod_id')
                ->join('concepts', 'item_prod_concepts.concept_id', '=', 'concepts.id')
                ->selectRaw('concepts.grade, COUNT(DISTINCT item_prod.id) as count')
                ->whereNotNull('concepts.grade')
                ->groupBy('concepts.grade')
                ->pluck('count', 'grade'),

            'strand' => $query->join('item_prod_concepts', 'item_prod.id', '=', 'item_prod_concepts.item_prod_id')
                ->join('concepts', 'item_prod_concepts.concept_id', '=', 'concepts.id')
                ->selectRaw('concepts.strand, COUNT(DISTINCT item_prod.id) as count')
                ->whereNotNull('concepts.strand')
                ->groupBy('concepts.strand')
                ->pluck('count', 'strand'),

            'type' => $query->selectRaw('item_type, COUNT(*) as count')
                ->groupBy('item_type')
                ->pluck('count', 'item_type'),

            'difficulty' => $query->selectRaw('
                    CASE
                        WHEN difficulty <= 2 THEN "Easy"
                        WHEN difficulty <= 3.5 THEN "Medium"
                        ELSE "Hard"
                    END as difficulty_level,
                    COUNT(*) as count
                ')
                ->groupBy('difficulty_level')
                ->pluck('count', 'difficulty_level'),

            default => [],
        };
    }

    private function getCriteriaAverages(array $dateRange, ?string $reviewerId): array
    {
        $reviews = ItemReview::whereBetween('created_at', $dateRange)
            ->when($reviewerId, fn($q) => $q->where('reviewer_id', $reviewerId))
            ->whereNotNull('rubric_scores')
            ->pluck('rubric_scores');

        $criteria = ['clarity', 'accuracy', 'difficulty', 'language', 'completeness'];
        $averages = [];

        foreach ($criteria as $criterion) {
            $scores = $reviews->pluck($criterion)->filter();
            $averages[$criterion] = $scores->count() > 0 ? $scores->average() : 0;
        }

        return $averages;
    }

    private function calculateRetentionRate(array $dateRange): float
    {
        // Simple retention: users who were active in both first and second half of period
        $midpoint = now()->subDays(
            now()->diffInDays($dateRange[0]) / 2
        );

        $firstHalfUsers = AuditLog::whereBetween('created_at', [$dateRange[0], $midpoint])
            ->distinct('user_id')
            ->count('user_id');

        $secondHalfUsers = AuditLog::whereBetween('created_at', [$midpoint, $dateRange[1]])
            ->distinct('user_id')
            ->count('user_id');

        $retainedUsers = AuditLog::whereBetween('created_at', [$dateRange[0], $midpoint])
            ->whereIn('user_id', function($query) use ($midpoint, $dateRange) {
                $query->select('user_id')
                    ->from('audit_logs')
                    ->whereBetween('created_at', [$midpoint, $dateRange[1]]);
            })
            ->distinct('user_id')
            ->count('user_id');

        return $firstHalfUsers > 0 ? ($retainedUsers / $firstHalfUsers) * 100 : 0;
    }
}
