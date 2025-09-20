<?php
// app/Http/Controllers/Api/ReviewController.php
namespace App\Http\Controllers\Api;

use App\Enums\ItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\{StoreReviewRequest, UpdateReviewRequest};
use App\Models\{ItemDraft, ItemReview};
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct()
    {
        /*$this->middleware('auth:sanctum');
        $this->middleware('can:review,App\Models\ItemReview')->except(['index', 'show']);*/
    }

    /**
     * List reviews (filtered by role)
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|string|in:pending,approved,changes_requested,rejected',
            'item_draft_id' => 'nullable|uuid|exists:item_drafts,id',
            'reviewer_id' => 'nullable|string',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'min_score' => 'nullable|numeric|min:0|max:5',
            'max_score' => 'nullable|numeric|min:0|max:5',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $query = ItemReview::with(['itemDraft.concepts', 'itemDraft.tags', 'reviewer'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->item_draft_id, fn($q) => $q->where('item_draft_id', $request->item_draft_id))
            ->when($request->reviewer_id, fn($q) => $q->where('reviewer_id', $request->reviewer_id))
            ->when($request->from_date, fn($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date, fn($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->when($request->min_score, fn($q) => $q->where('overall_score', '>=', $request->min_score))
            ->when($request->max_score, fn($q) => $q->where('overall_score', '<=', $request->max_score));

        // Filter based on user role
        if (!auth()->user()->is_admin) {
            if (auth()->user()->role === 'reviewer') {
                // Reviewers see reviews assigned to them and items in review
                $query->where(function($q) {
                    $q->where('reviewer_id', auth()->id())
                        ->orWhereHas('itemDraft', function($subQ) {
                            $subQ->where('status', ItemStatus::InReview);
                        });
                });
            } else {
                // Authors see only reviews of their items
                $query->whereHas('itemDraft', function($q) {
                    $q->where('created_by', auth()->id());
                });
            }
        }

        $reviews = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json(['data' => $reviews]);
    }

    /**
     * Create new review
     */
    public function store(StoreReviewRequest $request)
    {
        $itemDraft = ItemDraft::findOrFail($request->item_draft_id);

        // Check if item is in reviewable state
        if (!$itemDraft->canBeReviewed() && $itemDraft->status !== ItemStatus::InReview) {
            return response()->json([
                'error' => 'Item is not in a reviewable state.'
            ], 400);
        }

        // Check if reviewer already reviewed this item
        $existingReview = ItemReview::where('item_draft_id', $itemDraft->id)
            ->where('reviewer_id', auth()->id())
            ->first();

        if ($existingReview) {
            return response()->json([
                'error' => 'You have already reviewed this item. Use update instead.'
            ], 409);
        }

        // Calculate overall score from rubric if provided
        $overallScore = $this->calculateOverallScore($request->rubric_scores);

        $review = ItemReview::create([
            'item_draft_id' => $itemDraft->id,
            'reviewer_id' => auth()->id(),
            'status' => $request->status,
            'feedback' => $request->feedback,
            'rubric_scores' => $request->rubric_scores,
            'overall_score' => $overallScore,
        ]);

        // Update item draft status based on review
        $this->updateItemDraftStatus($itemDraft, $request->status);

        // Load relationships for response
        $review->load(['itemDraft', 'reviewer']);

        return response()->json([
            'data' => $review,
            'message' => 'Review submitted successfully.'
        ], 201);
    }

    /**
     * Get review details
     */
    public function show(ItemReview $review)
    {
        // Check access permissions
        if (!$this->canAccessReview($review)) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        $review->load([
            'itemDraft.concepts',
            'itemDraft.tags',
            'itemDraft.options',
            'itemDraft.hints',
            'itemDraft.solutions',
            'reviewer'
        ]);

        return response()->json(['data' => $review]);
    }

    /**
     * Update existing review
     */
    public function update(UpdateReviewRequest $request, ItemReview $review)
    {
        // Check ownership
        if ($review->reviewer_id !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        // Recalculate overall score if rubric updated
        $overallScore = $this->calculateOverallScore($request->rubric_scores);

        $review->update([
            'status' => $request->status,
            'feedback' => $request->feedback,
            'rubric_scores' => $request->rubric_scores,
            'overall_score' => $overallScore,
        ]);

        // Update item draft status if review status changed
        if ($review->wasChanged('status')) {
            $this->updateItemDraftStatus($review->itemDraft, $request->status);
        }

        $review->load(['itemDraft', 'reviewer']);

        return response()->json([
            'data' => $review,
            'message' => 'Review updated successfully.'
        ]);
    }

    /**
     * Delete review
     */
    public function destroy(ItemReview $review)
    {
        // Only admin or review owner can delete
        if ($review->reviewer_id !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        // Revert item draft status to in_review
        $review->itemDraft->update(['status' => ItemStatus::InReview]);

        $review->delete();

        return response()->json(['message' => 'Review deleted successfully.']);
    }

    /**
     * Get items pending review
     */
    public function pending(Request $request)
    {
        $this->authorize('review', ItemReview::class);

        $request->validate([
            'grade' => 'nullable|string',
            'strand' => 'nullable|string',
            'item_type' => 'nullable|string',
            'difficulty_min' => 'nullable|numeric|min:1|max:5',
            'difficulty_max' => 'nullable|numeric|min:1|max:5',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $query = ItemDraft::with(['concepts', 'tags', 'options', 'hints', 'solutions'])
            ->where('status', ItemStatus::InReview)
            ->whereDoesntHave('reviews', function($q) {
                $q->where('reviewer_id', auth()->id());
            })
            ->when($request->grade, function($q) use ($request) {
                $q->whereHas('concepts', fn($cq) => $cq->where('grade', $request->grade));
            })
            ->when($request->strand, function($q) use ($request) {
                $q->whereHas('concepts', fn($cq) => $cq->where('strand', $request->strand));
            })
            ->when($request->item_type, fn($q) => $q->where('item_type', $request->item_type))
            ->when($request->difficulty_min, fn($q) => $q->where('difficulty', '>=', $request->difficulty_min))
            ->when($request->difficulty_max, fn($q) => $q->where('difficulty', '<=', $request->difficulty_max))
            ->orderBy('created_at', 'asc');

        $items = $query->paginate($request->get('per_page', 15));

        return response()->json(['data' => $items]);
    }

    /**
     * Get review statistics
     */
    public function statistics(Request $request)
    {
        $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year',
            'reviewer_id' => 'nullable|string',
        ]);

        $period = $request->get('period', 'month');
        $reviewerId = $request->get('reviewer_id');

        // Calculate date range
        $dateRange = match($period) {
            'week' => [now()->subWeek(), now()],
            'month' => [now()->subMonth(), now()],
            'quarter' => [now()->subQuarter(), now()],
            'year' => [now()->subYear(), now()],
        };

        $query = ItemReview::whereBetween('created_at', $dateRange);

        // Filter by reviewer if specified (and user has permission)
        if ($reviewerId && (auth()->user()->is_admin || auth()->id() === $reviewerId)) {
            $query->where('reviewer_id', $reviewerId);
        } elseif (!auth()->user()->is_admin) {
            $query->where('reviewer_id', auth()->id());
        }

        $stats = [
            'total_reviews' => $query->count(),
            'reviews_by_status' => $query->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'average_score' => $query->whereNotNull('overall_score')->avg('overall_score'),
            'reviews_per_day' => $query->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('count', 'date'),
            'top_reviewers' => auth()->user()->is_admin
                ? ItemReview::whereBetween('created_at', $dateRange)
                    ->with('reviewer:id,name')
                    ->selectRaw('reviewer_id, COUNT(*) as review_count, AVG(overall_score) as avg_score')
                    ->groupBy('reviewer_id')
                    ->orderBy('review_count', 'desc')
                    ->limit(10)
                    ->get()
                : null,
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Get review rubric template
     */
    public function rubricTemplate()
    {
        $rubric = [
            'criteria' => [
                [
                    'name' => 'clarity',
                    'label' => 'وضوح السؤال',
                    'description' => 'هل السؤال واضح ومفهوم؟',
                    'weight' => 0.25,
                    'scale' => [
                        1 => 'غير واضح تماماً',
                        2 => 'غير واضح إلى حد ما',
                        3 => 'واضح نسبياً',
                        4 => 'واضح',
                        5 => 'واضح تماماً'
                    ]
                ],
                [
                    'name' => 'accuracy',
                    'label' => 'دقة المحتوى',
                    'description' => 'هل المحتوى العلمي صحيح؟',
                    'weight' => 0.30,
                    'scale' => [
                        1 => 'خطأ فادح',
                        2 => 'أخطاء متعددة',
                        3 => 'خطأ بسيط',
                        4 => 'دقيق تقريباً',
                        5 => 'دقيق تماماً'
                    ]
                ],
                [
                    'name' => 'difficulty',
                    'label' => 'مناسبة مستوى الصعوبة',
                    'description' => 'هل مستوى الصعوبة مناسب للمرحلة؟',
                    'weight' => 0.20,
                    'scale' => [
                        1 => 'سهل جداً أو صعب جداً',
                        2 => 'غير مناسب',
                        3 => 'مقبول',
                        4 => 'مناسب',
                        5 => 'مناسب جداً'
                    ]
                ],
                [
                    'name' => 'language',
                    'label' => 'جودة اللغة',
                    'description' => 'هل اللغة المستخدمة سليمة؟',
                    'weight' => 0.15,
                    'scale' => [
                        1 => 'أخطاء كثيرة',
                        2 => 'أخطاء متوسطة',
                        3 => 'أخطاء قليلة',
                        4 => 'سليمة تقريباً',
                        5 => 'سليمة تماماً'
                    ]
                ],
                [
                    'name' => 'completeness',
                    'label' => 'اكتمال السؤال',
                    'description' => 'هل السؤال مكتمل (خيارات، حلول، تلميحات)؟',
                    'weight' => 0.10,
                    'scale' => [
                        1 => 'ناقص جداً',
                        2 => 'ناقص',
                        3 => 'مقبول',
                        4 => 'مكتمل تقريباً',
                        5 => 'مكتمل تماماً'
                    ]
                ]
            ],
            'overall_calculation' => 'weighted_average',
            'passing_score' => 3.0,
        ];

        return response()->json(['data' => $rubric]);
    }

    /**
     * Bulk review actions
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|string|in:approve,request_changes,reject',
            'item_draft_ids' => 'required|array|max:20',
            'item_draft_ids.*' => 'uuid|exists:item_drafts,id',
            'feedback' => 'nullable|string|max:1000',
            'rubric_scores' => 'nullable|array',
        ]);

        $this->authorize('review', ItemReview::class);

        $itemDrafts = ItemDraft::whereIn('id', $request->item_draft_ids)
            ->where('status', ItemStatus::InReview)
            ->get();

        if ($itemDrafts->count() !== count($request->item_draft_ids)) {
            return response()->json([
                'error' => 'Some items are not available for review.'
            ], 400);
        }

        $reviewStatus = match($request->action) {
            'approve' => 'approved',
            'request_changes' => 'changes_requested',
            'reject' => 'rejected',
        };

        $reviews = [];
        foreach ($itemDrafts as $itemDraft) {
            $review = ItemReview::create([
                'item_draft_id' => $itemDraft->id,
                'reviewer_id' => auth()->id(),
                'status' => $reviewStatus,
                'feedback' => $request->feedback,
                'rubric_scores' => $request->rubric_scores,
                'overall_score' => $this->calculateOverallScore($request->rubric_scores),
            ]);

            $this->updateItemDraftStatus($itemDraft, $reviewStatus);
            $reviews[] = $review;
        }

        return response()->json([
            'data' => $reviews,
            'message' => "Successfully {$request->action}d {$itemDrafts->count()} items."
        ]);
    }

    private function calculateOverallScore(?array $rubricScores): ?float
    {
        if (!$rubricScores || empty($rubricScores)) {
            return null;
        }

        // Get weights from rubric template (could be cached)
        $weights = [
            'clarity' => 0.25,
            'accuracy' => 0.30,
            'difficulty' => 0.20,
            'language' => 0.15,
            'completeness' => 0.10,
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($rubricScores as $criterion => $score) {
            $weight = $weights[$criterion] ?? 0.2; // Default weight
            $totalScore += $score * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($totalScore / $totalWeight, 1) : null;
    }

    private function updateItemDraftStatus(ItemDraft $itemDraft, string $reviewStatus): void
    {
        $newStatus = match($reviewStatus) {
            'approved' => ItemStatus::Approved,
            'changes_requested' => ItemStatus::ChangesRequested,
            'rejected' => ItemStatus::Archived,
            default => $itemDraft->status,
        };

        $itemDraft->update(['status' => $newStatus]);
    }

    private function canAccessReview(ItemReview $review): bool
    {
        return auth()->user()->is_admin
            || $review->reviewer_id === auth()->id()
            || $review->itemDraft->created_by === auth()->id();
    }
}
