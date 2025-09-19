<?php
// app/Http/Controllers/Api/ItemProdController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{ItemProd};
use App\Http\Requests\ItemProdFilterRequest;
use Illuminate\Http\{Request, JsonResponse};

class ItemProdController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('throttle:api');
    }

    /**
     * List published items with advanced filtering
     */
    public function index(ItemProdFilterRequest $request): JsonResponse
    {
        $query = ItemProd::with(['concepts', 'tags', 'options', 'hints', 'solutions', 'publisher'])
            ->when($request->item_type, fn($q) => $q->where('item_type', $request->item_type))
            ->when($request->difficulty_min, fn($q) => $q->where('difficulty', '>=', $request->difficulty_min))
            ->when($request->difficulty_max, fn($q) => $q->where('difficulty', '<=', $request->difficulty_max))
            ->when($request->grade, fn($q) => $q->byGrade($request->grade))
            ->when($request->strand, fn($q) => $q->byStrand($request->strand))
            ->when($request->concept_ids, fn($q) => $q->whereHas('concepts', function($cq) use ($request) {
                $cq->whereIn('concepts.id', $request->concept_ids);
            }))
            ->when($request->tag_codes, fn($q) => $q->whereHas('tags', function($tq) use ($request) {
                $tq->whereIn('tags.code', $request->tag_codes);
            }))
            ->when($request->has_solutions, fn($q) => $q->has('solutions'))
            ->when($request->has_hints, fn($q) => $q->has('hints'))
            ->when($request->published_after, fn($q) => $q->where('published_at', '>=', $request->published_after))
            ->when($request->published_before, fn($q) => $q->where('published_at', '<=', $request->published_before))
            ->when($request->search, function($q) use ($request) {
                $q->where('stem_ar', 'LIKE', "%{$request->search}%");
            });

        // Sorting
        $sortBy = $request->get('sort_by', 'published_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if ($sortBy === 'quality_score') {
            // Custom quality scoring
            $query->selectRaw('*, (
                (CASE WHEN EXISTS(SELECT 1 FROM item_solutions WHERE itemable_id = item_prod.id AND itemable_type = "App\\\\Models\\\\ItemProd") THEN 25 ELSE 0 END) +
                (CASE WHEN EXISTS(SELECT 1 FROM item_hints WHERE itemable_id = item_prod.id AND itemable_type = "App\\\\Models\\\\ItemProd") THEN 15 ELSE 0 END) +
                (CASE WHEN latex IS NOT NULL AND latex != "" THEN 10 ELSE 0 END) +
                (CASE WHEN difficulty BETWEEN 2 AND 4 THEN 10 ELSE 5 END) +
                (SELECT COUNT(*) * 5 FROM item_prod_concepts WHERE item_prod_id = item_prod.id LIMIT 4)
            ) as quality_score')
                ->orderBy('quality_score', $sortOrder);
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $items = $query->paginate($request->get('per_page', 15));

        // Add computed fields
        $items->getCollection()->transform(function ($item) {
            $item->quality_score = $item->getQualityScore();
            $item->complexity_score = $item->getComplexityScore();
            return $item;
        });

        return response()->json(['data' => $items]);
    }

    /**
     * Get specific published item
     */
    public function show(ItemProd $itemProd): JsonResponse
    {
        $itemProd->load([
            'concepts',
            'tags',
            'options' => fn($q) => $q->orderBy('order_index'),
            'hints' => fn($q) => $q->orderBy('order_index'),
            'solutions',
            'publisher',
            'sourceDraft.creator',
            'mediaAssets',
        ]);

        // Add analytics data
        $itemProd->analytics = [
            'quality_score' => $itemProd->getQualityScore(),
            'complexity_score' => $itemProd->getComplexityScore(),
            'similar_items_count' => $itemProd->getSimilarItems(10)->count(),
            'grades' => $itemProd->getGrades(),
            'strands' => $itemProd->getStrands(),
        ];

        return response()->json(['data' => $itemProd]);
    }

    /**
     * Get similar items
     */
    public function similar(Request $request, ItemProd $itemProd): JsonResponse
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:20',
            'include_metadata' => 'nullable|boolean',
        ]);

        $limit = $request->get('limit', 5);
        $includeMetadata = $request->get('include_metadata', false);

        $similarItems = $itemProd->getSimilarItems($limit);

        if ($includeMetadata) {
            $similarItems->load(['concepts', 'tags', 'publisher']);
        }

        return response()->json([
            'data' => $similarItems,
            'metadata' => [
                'source_item_id' => $itemProd->id,
                'similarity_criteria' => [
                    'item_type' => $itemProd->item_type,
                    'difficulty_range' => [$itemProd->difficulty - 0.5, $itemProd->difficulty + 0.5],
                    'shared_concepts' => $itemProd->concepts->pluck('id'),
                ],
            ],
        ]);
    }

    /**
     * Export items to various formats
     */
    public function export(Request $request): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1|max:50',
            'item_ids.*' => 'uuid|exists:item_prod,id',
            'format' => 'required|string|in:json,csv,xml,pdf',
            'include_solutions' => 'nullable|boolean',
            'include_hints' => 'nullable|boolean',
            'include_media' => 'nullable|boolean',
        ]);

        $items = ItemProd::with(['concepts', 'tags', 'options', 'hints', 'solutions'])
            ->whereIn('id', $request->item_ids)
            ->get();

        $exportData = $items->map(function ($item) use ($request) {
            $data = $item->toExportArray();

            if (!$request->get('include_solutions', true)) {
                unset($data['solutions']);
            }

            if (!$request->get('include_hints', true)) {
                unset($data['hints']);
            }

            return $data;
        });

        return response()->json([
            'data' => $exportData,
            'metadata' => [
                'exported_at' => now()->toISOString(),
                'format' => $request->get('format'),
                'item_count' => $items->count(),
                'include_solutions' => $request->get('include_solutions', true),
                'include_hints' => $request->get('include_hints', true),
            ],
        ]);
    }

    /**
     * Get item statistics and analytics
     */
    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:7d,30d,90d,1y',
            'group_by' => 'nullable|string|in:type,difficulty,grade,strand',
        ]);

        $period = $request->get('period', '30d');
        $groupBy = $request->get('group_by');

        $dateRange = match($period) {
            '7d' => [now()->subDays(7), now()],
            '30d' => [now()->subDays(30), now()],
            '90d' => [now()->subDays(90), now()],
            '1y' => [now()->subYear(), now()],
        };

        $stats = [
            'overview' => ItemProd::getContentStats(),
            'recent_publications' => ItemProd::whereBetween('published_at', $dateRange)->count(),
            'quality_distribution' => ItemProd::selectRaw('
                    CASE
                        WHEN (
                            (CASE WHEN EXISTS(SELECT 1 FROM item_solutions WHERE itemable_id = item_prod.id) THEN 25 ELSE 0 END) +
                            (CASE WHEN EXISTS(SELECT 1 FROM item_hints WHERE itemable_id = item_prod.id) THEN 15 ELSE 0 END) +
                            (CASE WHEN latex IS NOT NULL THEN 10 ELSE 0 END)
                        ) >= 40 THEN "High Quality"
                        WHEN (
                            (CASE WHEN EXISTS(SELECT 1 FROM item_solutions WHERE itemable_id = item_prod.id) THEN 25 ELSE 0 END) +
                            (CASE WHEN EXISTS(SELECT 1 FROM item_hints WHERE itemable_id = item_prod.id) THEN 15 ELSE 0 END) +
                            (CASE WHEN latex IS NOT NULL THEN 10 ELSE 0 END)
                        ) >= 20 THEN "Medium Quality"
                        ELSE "Basic Quality"
                    END as quality_level,
                    COUNT(*) as count
                ')
                ->groupBy('quality_level')
                ->pluck('count', 'quality_level'),
        ];

        if ($groupBy) {
            $stats['grouped_data'] = $this->getGroupedStatistics($groupBy, $dateRange);
        }

        return response()->json(['data' => $stats]);
    }

    /**
     * Search published items using full-text search
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:200',
            'filters' => 'nullable|array',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $query = $request->get('q');
        $filters = $request->get('filters', []);
        $perPage = $request->get('per_page', 15);

        // Use Scout search if available, otherwise fallback to database search
        if (class_exists('\Laravel\Scout\Searchable')) {
            $search = ItemProd::search($query);

            // Apply filters through Scout
            foreach ($filters as $key => $value) {
                if (!empty($value)) {
                    $search->where($key, $value);
                }
            }

            $items = $search->paginate($perPage);
        } else {
            // Fallback database search
            $items = ItemProd::with(['concepts', 'tags'])
                ->where('stem_ar', 'LIKE', "%{$query}%")
                ->when(!empty($filters['item_type']), fn($q) => $q->where('item_type', $filters['item_type']))
                ->when(!empty($filters['difficulty_min']), fn($q) => $q->where('difficulty', '>=', $filters['difficulty_min']))
                ->when(!empty($filters['difficulty_max']), fn($q) => $q->where('difficulty', '<=', $filters['difficulty_max']))
                ->orderBy('published_at', 'desc')
                ->paginate($perPage);
        }

        return response()->json([
            'data' => $items,
            'search_metadata' => [
                'query' => $query,
                'filters_applied' => count(array_filter($filters)),
                'search_time' => microtime(true) - request()->server('REQUEST_TIME_FLOAT'),
            ],
        ]);
    }

    /**
     * Get random items for sampling or testing
     */
    public function random(Request $request): JsonResponse
    {
        $request->validate([
            'count' => 'nullable|integer|min:1|max:20',
            'item_type' => 'nullable|string',
            'difficulty_range' => 'nullable|array|size:2',
            'include_metadata' => 'nullable|boolean',
        ]);

        $count = $request->get('count', 5);
        $includeMetadata = $request->get('include_metadata', false);

        $query = ItemProd::inRandomOrder()
            ->when($request->item_type, fn($q) => $q->where('item_type', $request->item_type))
            ->when($request->difficulty_range, fn($q) => $q->whereBetween('difficulty', $request->difficulty_range))
            ->limit($count);

        if ($includeMetadata) {
            $query->with(['concepts', 'tags', 'options', 'hints']);
        }

        $items = $query->get();

        return response()->json([
            'data' => $items,
            'metadata' => [
                'requested_count' => $count,
                'returned_count' => $items->count(),
                'selection_criteria' => array_filter([
                    'item_type' => $request->item_type,
                    'difficulty_range' => $request->difficulty_range,
                ]),
            ],
        ]);
    }

    private function getGroupedStatistics(string $groupBy, array $dateRange): array
    {
        return match($groupBy) {
            'type' => ItemProd::whereBetween('published_at', $dateRange)
                ->selectRaw('item_type, COUNT(*) as count, AVG(difficulty) as avg_difficulty')
                ->groupBy('item_type')
                ->get()
                ->keyBy('item_type'),

            'difficulty' => ItemProd::whereBetween('published_at', $dateRange)
                ->selectRaw('
                    CASE
                        WHEN difficulty <= 2 THEN "Easy"
                        WHEN difficulty <= 3.5 THEN "Medium"
                        ELSE "Hard"
                    END as difficulty_level,
                    COUNT(*) as count
                ')
                ->groupBy('difficulty_level')
                ->pluck('count', 'difficulty_level'),

            'grade' => ItemProd::join('item_prod_concepts', 'item_prod.id', '=', 'item_prod_concepts.item_prod_id')
                ->join('concepts', 'item_prod_concepts.concept_id', '=', 'concepts.id')
                ->whereBetween('item_prod.published_at', $dateRange)
                ->selectRaw('concepts.grade, COUNT(DISTINCT item_prod.id) as count')
                ->whereNotNull('concepts.grade')
                ->groupBy('concepts.grade')
                ->pluck('count', 'grade'),

            'strand' => ItemProd::join('item_prod_concepts', 'item_prod.id', '=', 'item_prod_concepts.item_prod_id')
                ->join('concepts', 'item_prod_concepts.concept_id', '=', 'concepts.id')
                ->whereBetween('item_prod.published_at', $dateRange)
                ->selectRaw('concepts.strand, COUNT(DISTINCT item_prod.id) as count')
                ->whereNotNull('concepts.strand')
                ->groupBy('concepts.strand')
                ->pluck('count', 'strand'),

            default => [],
        };
    }
}
