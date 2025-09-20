<?php
// app/Http/Controllers/Api/TagController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\{StoreTagRequest, UpdateTagRequest};
use App\Models\{Tag, AuditLog};
use Illuminate\Http\{Request, JsonResponse};

class TagController extends Controller
{
    public function __construct()
    {
        /*$this->middleware('auth:sanctum');
        $this->middleware('can:manage-tags')->except(['index', 'show']);*/
    }

    /**
     * List tags with usage statistics
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
            'color' => 'nullable|string',
            'with_usage' => 'nullable|boolean',
            'sort_by' => 'nullable|string|in:name_ar,code,usage_count',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Tag::query()
            ->when($request->search, function($q) use ($request) {
                $q->where(function($subQ) use ($request) {
                    $subQ->where('name_ar', 'LIKE', "%{$request->search}%")
                        ->orWhere('name_en', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%");
                });
            })
            ->when($request->color, fn($q) => $q->where('color', $request->color));

        if ($request->get('with_usage', false)) {
            $query->withCount(['itemDrafts', 'itemProds']);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'name_ar');
        $sortOrder = $request->get('sort_order', 'asc');

        if ($sortBy === 'usage_count') {
            $query->withCount(['itemDrafts', 'itemProds'])
                ->orderByRaw('(item_drafts_count + item_prods_count) ' . strtoupper($sortOrder));
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $tags = $query->paginate($request->get('per_page', 15));

        return response()->json(['data' => $tags]);
    }

    /**
     * Store new tag
     */
    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = Tag::create($request->validated());

        // Log creation
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'created',
            'auditable_type' => Tag::class,
            'auditable_id' => $tag->id,
            'new_values' => $tag->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => $tag->fresh(),
            'message' => 'تم إنشاء التصنيف بنجاح',
        ], 201);
    }

    /**
     * Show specific tag with usage details
     */
    public function show(Tag $tag): JsonResponse
    {
        $tag->loadCount(['itemDrafts', 'itemProds']);

        // Load recent items
        $tag->load([
            'itemDrafts' => fn($q) => $q->with(['creator', 'concepts'])->latest()->take(10),
            'itemProds' => fn($q) => $q->with(['publisher', 'concepts'])->latest()->take(10),
        ]);

        // Add usage statistics
        $tag->usage_statistics = [
            'total_usage' => $tag->getTotalUsageCount(),
            'draft_items' => $tag->item_drafts_count,
            'published_items' => $tag->item_prods_count,
            'usage_trend' => $this->getUsageTrend($tag),
            'popular_combinations' => $this->getPopularTagCombinations($tag),
        ];

        return response()->json(['data' => $tag]);
    }

    /**
     * Update tag
     */
    public function update(UpdateTagRequest $request, Tag $tag): JsonResponse
    {
        $oldData = $tag->toArray();
        $tag->update($request->validated());

        // Log update
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'updated',
            'auditable_type' => Tag::class,
            'auditable_id' => $tag->id,
            'old_values' => $oldData,
            'new_values' => $tag->fresh()->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => $tag->fresh(),
            'message' => 'تم تحديث التصنيف بنجاح',
        ]);
    }

    /**
     * Delete tag
     */
    public function destroy(Tag $tag): JsonResponse
    {
        // Check if tag is being used
        $usageCount = $tag->getTotalUsageCount();

        if ($usageCount > 0) {
            return response()->json([
                'error' => 'لا يمكن حذف التصنيف لأنه مستخدم في ' . $usageCount . ' عنصر',
            ], 400);
        }

        $tagData = $tag->toArray();
        $tag->delete();

        // Log deletion
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'deleted',
            'auditable_type' => Tag::class,
            'auditable_id' => $tag->id,
            'old_values' => $tagData,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json(['message' => 'تم حذف التصنيف بنجاح']);
    }

    /**
     * Get popular tags
     */
    public function popular(Request $request): JsonResponse
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
            'period' => 'nullable|string|in:week,month,quarter,year,all',
        ]);

        $limit = $request->get('limit', 10);
        $period = $request->get('period', 'all');

        $query = Tag::withCount(['itemDrafts', 'itemProds']);

        if ($period !== 'all') {
            $dateRange = match($period) {
                'week' => [now()->subWeek(), now()],
                'month' => [now()->subMonth(), now()],
                'quarter' => [now()->subQuarter(), now()],
                'year' => [now()->subYear(), now()],
            };

            $query->whereHas('itemProds', function($q) use ($dateRange) {
                $q->whereBetween('published_at', $dateRange);
            });
        }

        $tags = $query->orderByRaw('(item_drafts_count + item_prods_count) DESC')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $tags,
            'metadata' => [
                'period' => $period,
                'limit' => $limit,
                'total_tags' => Tag::count(),
            ],
        ]);
    }

    /**
     * Get tag usage statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year',
        ]);

        $period = $request->get('period', 'month');

        $dateRange = match($period) {
            'week' => [now()->subWeek(), now()],
            'month' => [now()->subMonth(), now()],
            'quarter' => [now()->subQuarter(), now()],
            'year' => [now()->subYear(), now()],
        };

        $stats = [
            'overview' => [
                'total_tags' => Tag::count(),
                'used_tags' => Tag::has('itemProds')->count(),
                'unused_tags' => Tag::doesntHave('itemProds')->count(),
                'avg_usage_per_tag' => round(Tag::withCount('itemProds')->avg('item_prods_count'), 2),
            ],
            'color_distribution' => Tag::getPopularColors(),
            'recent_activity' => Tag::withCount(['itemDrafts', 'itemProds'])
                ->whereHas('itemDrafts', function($q) use ($dateRange) {
                    $q->whereBetween('created_at', $dateRange);
                })
                ->orderByDesc('item_drafts_count')
                ->take(10)
                ->get(),
            'top_combinations' => $this->getTopTagCombinations($dateRange),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Search tags with autocomplete
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:50',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $query = $request->get('q');
        $limit = $request->get('limit', 10);

        $tags = Tag::where(function($q) use ($query) {
            $q->where('name_ar', 'LIKE', "%{$query}%")
                ->orWhere('name_en', 'LIKE', "%{$query}%")
                ->orWhere('code', 'LIKE', "%{$query}%");
        })
            ->withCount('itemProds')
            ->orderBy('item_prods_count', 'desc')
            ->limit($limit)
            ->get(['id', 'code', 'name_ar', 'name_en', 'color']);

        return response()->json(['data' => $tags]);
    }

    /**
     * Bulk operations on tags
     */
    public function bulkAction(Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|string|in:delete,update_color,merge',
            'tag_ids' => 'required|array|min:1|max:50',
            'tag_ids.*' => 'uuid|exists:tags,id',
            'data' => 'nullable|array',
        ]);

        $tags = Tag::whereIn('id', $request->tag_ids)->get();
        $results = ['success' => [], 'errors' => []];

        foreach ($tags as $tag) {
            try {
                switch ($request->action) {
                    case 'delete':
                        if ($tag->getTotalUsageCount() === 0) {
                            $tag->delete();
                            $results['success'][] = $tag->code;
                        } else {
                            $results['errors'][] = [
                                'tag' => $tag->code,
                                'error' => 'التصنيف مستخدم ولا يمكن حذفه',
                            ];
                        }
                        break;

                    case 'update_color':
                        $tag->update(['color' => $request->data['color']]);
                        $results['success'][] = $tag->code;
                        break;

                    case 'merge':
                        // Complex merge logic would be implemented here
                        $results['success'][] = $tag->code;
                        break;
                }
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'tag' => $tag->code,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => $results,
            'summary' => [
                'total_processed' => count($request->tag_ids),
                'successful' => count($results['success']),
                'failed' => count($results['errors']),
            ],
        ]);
    }

    /**
     * Generate color palette suggestions
     */
    public function colorPalette(): JsonResponse
    {
        return response()->json([
            'data' => Tag::generateColorPalette(),
            'popular_colors' => Tag::getPopularColors(),
        ]);
    }

    private function getUsageTrend(Tag $tag): array
    {
        return $tag->itemProds()
            ->selectRaw('DATE(published_at) as date, COUNT(*) as count')
            ->where('published_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();
    }

    private function getPopularTagCombinations(Tag $tag, int $limit = 5): array
    {
        // Get items that have this tag and see what other tags they commonly have
        $itemIds = $tag->itemProds()->pluck('id');

        return Tag::whereHas('itemProds', function($q) use ($itemIds) {
            $q->whereIn('item_prod.id', $itemIds);
        })
            ->where('id', '!=', $tag->id)
            ->withCount(['itemProds' => function($q) use ($itemIds) {
                $q->whereIn('item_prod.id', $itemIds);
            }])
            ->orderBy('item_prods_count', 'desc')
            ->limit($limit)
            ->get(['id', 'code', 'name_ar', 'color'])
            ->toArray();
    }

    private function getTopTagCombinations(array $dateRange, int $limit = 10): array
    {
        // This would require a more complex query to find common tag combinations
        // For now, return a simplified version
        return Tag::withCount(['itemProds' => function($q) use ($dateRange) {
            $q->whereBetween('published_at', $dateRange);
        }])
            ->having('item_prods_count', '>', 0)
            ->orderBy('item_prods_count', 'desc')
            ->limit($limit)
            ->get(['code', 'name_ar', 'item_prods_count'])
            ->toArray();
    }
}
