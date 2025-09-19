<?php
// app/Http/Controllers/Api/SearchController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{ItemDraft, ItemProd, Concept, Tag};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SearchController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('throttle:search');
    }

    /**
     * Search through item drafts with advanced filters
     */
    public function drafts(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:200',
            'filters' => 'nullable|array',
            'filters.status' => 'nullable|string|in:draft,in_review,changes_requested,approved,published,archived',
            'filters.item_type' => 'nullable|string|in:multiple_choice,short_answer,essay,numerical,matching',
            'filters.difficulty_min' => 'nullable|numeric|min:1|max:5',
            'filters.difficulty_max' => 'nullable|numeric|min:1|max:5',
            'filters.grade' => 'nullable|string',
            'filters.strand' => 'nullable|string',
            'filters.tags' => 'nullable|array',
            'filters.tags.*' => 'string',
            'filters.concepts' => 'nullable|array',
            'filters.concepts.*' => 'uuid',
            'filters.created_by' => 'nullable|string',
            'filters.from_date' => 'nullable|date',
            'filters.to_date' => 'nullable|date',
            'sort_by' => 'nullable|string|in:created_at,updated_at,difficulty,stem_ar',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'integer|min:1|max:100',
        ]);

        $query = $request->get('q', '');
        $filters = $request->get('filters', []);
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $perPage = $request->get('per_page', 15);

        // Build cache key for this search
        $cacheKey = 'search_drafts_' . md5(json_encode($request->all()));

        $results = Cache::remember($cacheKey, 300, function() use ($query, $filters, $sortBy, $sortOrder, $perPage) {
            // Start with Scout search if query provided
            if (!empty($query)) {
                $search = ItemDraft::search($query);

                // Apply Scout filters
                if (!empty($filters['status'])) {
                    $search->where('status', $filters['status']);
                }
                if (!empty($filters['item_type'])) {
                    $search->where('item_type', $filters['item_type']);
                }
                if (!empty($filters['tags'])) {
                    $search->whereIn('tags', $filters['tags']);
                }

                $itemIds = $search->keys();
                $baseQuery = ItemDraft::whereIn('id', $itemIds);
            } else {
                $baseQuery = ItemDraft::query();
            }

            // Apply additional filters that Scout can't handle
            $baseQuery->with(['concepts', 'tags', 'reviews', 'contentHash'])
                ->when(!empty($filters['difficulty_min']), function($q) use ($filters) {
                    $q->where('difficulty', '>=', $filters['difficulty_min']);
                })
                ->when(!empty($filters['difficulty_max']), function($q) use ($filters) {
                    $q->where('difficulty', '<=', $filters['difficulty_max']);
                })
                ->when(!empty($filters['grade']), function($q) use ($filters) {
                    $q->whereHas('concepts', function($cq) use ($filters) {
                        $cq->where('grade', $filters['grade']);
                    });
                })
                ->when(!empty($filters['strand']), function($q) use ($filters) {
                    $q->whereHas('concepts', function($cq) use ($filters) {
                        $cq->where('strand', $filters['strand']);
                    });
                })
                ->when(!empty($filters['concepts']), function($q) use ($filters) {
                    $q->whereHas('concepts', function($cq) use ($filters) {
                        $cq->whereIn('concepts.id', $filters['concepts']);
                    });
                })
                ->when(!empty($filters['created_by']), function($q) use ($filters) {
                    $q->where('created_by', $filters['created_by']);
                })
                ->when(!empty($filters['from_date']), function($q) use ($filters) {
                    $q->where('created_at', '>=', $filters['from_date']);
                })
                ->when(!empty($filters['to_date']), function($q) use ($filters) {
                    $q->where('created_at', '<=', $filters['to_date']);
                })
                ->orderBy($sortBy, $sortOrder);

            return $baseQuery->paginate($perPage);
        });

        // Add search metadata
        $metadata = [
            'total_results' => $results->total(),
            'search_time' => microtime(true) - LARAVEL_START,
            'filters_applied' => count(array_filter($filters)),
            'cached' => Cache::has($cacheKey),
        ];

        return response()->json([
            'data' => $results,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Search published items
     */
    public function items(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:200',
            'filters' => 'nullable|array',
            'filters.item_type' => 'nullable|string',
            'filters.difficulty_min' => 'nullable|numeric|min:1|max:5',
            'filters.difficulty_max' => 'nullable|numeric|min:1|max:5',
            'filters.grade' => 'nullable|string',
            'filters.strand' => 'nullable|string',
            'filters.published_after' => 'nullable|date',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $query = $request->get('q', '');
        $filters = $request->get('filters', []);
        $perPage = $request->get('per_page', 15);

        if (!empty($query)) {
            $search = ItemProd::search($query);
            $itemIds = $search->keys();
            $baseQuery = ItemProd::whereIn('id', $itemIds);
        } else {
            $baseQuery = ItemProd::query();
        }

        $results = $baseQuery->with(['concepts', 'tags', 'options', 'hints', 'solutions'])
            ->when(!empty($filters['item_type']), function($q) use ($filters) {
                $q->where('item_type', $filters['item_type']);
            })
            ->when(!empty($filters['difficulty_min']), function($q) use ($filters) {
                $q->where('difficulty', '>=', $filters['difficulty_min']);
            })
            ->when(!empty($filters['difficulty_max']), function($q) use ($filters) {
                $q->where('difficulty', '<=', $filters['difficulty_max']);
            })
            ->when(!empty($filters['grade']), function($q) use ($filters) {
                $q->whereHas('concepts', function($cq) use ($filters) {
                    $cq->where('grade', $filters['grade']);
                });
            })
            ->when(!empty($filters['strand']), function($q) use ($filters) {
                $q->whereHas('concepts', function($cq) use ($filters) {
                    $cq->where('strand', $filters['strand']);
                });
            })
            ->when(!empty($filters['published_after']), function($q) use ($filters) {
                $q->where('published_at', '>=', $filters['published_after']);
            })
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);

        return response()->json(['data' => $results]);
    }

    /**
     * Search concepts with curriculum filtering
     */
    public function concepts(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:200',
            'grade' => 'nullable|string',
            'strand' => 'nullable|string',
            'with_items_count' => 'nullable|boolean',
        ]);

        $query = $request->get('q', '');
        $grade = $request->get('grade');
        $strand = $request->get('strand');
        $withItemsCount = $request->get('with_items_count', false);

        $cacheKey = 'search_concepts_' . md5($request->getQueryString());

        $results = Cache::remember($cacheKey, 600, function() use ($query, $grade, $strand, $withItemsCount) {
            if (!empty($query)) {
                $search = Concept::search($query);
                if ($grade) $search->where('grade', $grade);
                if ($strand) $search->where('strand', $strand);
                $conceptIds = $search->keys();
                $baseQuery = Concept::whereIn('id', $conceptIds);
            } else {
                $baseQuery = Concept::query()
                    ->when($grade, fn($q) => $q->where('grade', $grade))
                    ->when($strand, fn($q) => $q->where('strand', $strand));
            }

            if ($withItemsCount) {
                $baseQuery->withCount(['itemDrafts', 'itemProds']);
            }

            return $baseQuery->orderBy('name_ar')->get();
        });

        return response()->json(['data' => $results]);
    }

    /**
     * Search tags
     */
    public function tags(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'with_usage_count' => 'nullable|boolean',
        ]);

        $query = $request->get('q', '');
        $withUsageCount = $request->get('with_usage_count', false);

        $baseQuery = Tag::query();

        if (!empty($query)) {
            $baseQuery->where(function($q) use ($query) {
                $q->where('name_ar', 'LIKE', "%{$query}%")
                    ->orWhere('name_en', 'LIKE', "%{$query}%")
                    ->orWhere('code', 'LIKE', "%{$query}%");
            });
        }

        if ($withUsageCount) {
            $baseQuery->withCount(['itemDrafts', 'itemProds']);
        }

        $results = $baseQuery->orderBy('name_ar')->get();

        return response()->json(['data' => $results]);
    }

    /**
     * Get search suggestions/autocomplete
     */
    public function suggestions(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:2|max:50',
            'type' => 'required|string|in:concepts,tags,items',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $query = $request->get('q');
        $type = $request->get('type');
        $limit = $request->get('limit', 10);

        $cacheKey = "search_suggestions_{$type}_{$query}_{$limit}";

        $suggestions = Cache::remember($cacheKey, 300, function() use ($query, $type, $limit) {
            switch ($type) {
                case 'concepts':
                    return Concept::where('name_ar', 'LIKE', "%{$query}%")
                        ->orWhere('name_en', 'LIKE', "%{$query}%")
                        ->limit($limit)
                        ->get(['id', 'name_ar', 'name_en', 'code']);

                case 'tags':
                    return Tag::where('name_ar', 'LIKE', "%{$query}%")
                        ->orWhere('name_en', 'LIKE', "%{$query}%")
                        ->orWhere('code', 'LIKE', "%{$query}%")
                        ->limit($limit)
                        ->get(['id', 'name_ar', 'name_en', 'code']);

                case 'items':
                    return ItemDraft::where('stem_ar', 'LIKE', "%{$query}%")
                        ->limit($limit)
                        ->get(['id', 'stem_ar', 'item_type']);

                default:
                    return collect();
            }
        });

        return response()->json(['data' => $suggestions]);
    }

    /**
     * Advanced search with complex filters
     */
    public function advanced(Request $request)
    {
        $request->validate([
            'queries' => 'required|array|max:5',
            'queries.*.field' => 'required|string|in:stem_ar,latex,item_type,difficulty',
            'queries.*.operator' => 'required|string|in:contains,equals,greater_than,less_than',
            'queries.*.value' => 'required|string',
            'logical_operator' => 'nullable|string|in:and,or',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $queries = $request->get('queries');
        $logicalOperator = $request->get('logical_operator', 'and');
        $perPage = $request->get('per_page', 15);

        $baseQuery = ItemDraft::with(['concepts', 'tags']);

        foreach ($queries as $queryItem) {
            $method = $logicalOperator === 'or' ? 'orWhere' : 'where';

            switch ($queryItem['operator']) {
                case 'contains':
                    $baseQuery->{$method}($queryItem['field'], 'LIKE', "%{$queryItem['value']}%");
                    break;
                case 'equals':
                    $baseQuery->{$method}($queryItem['field'], $queryItem['value']);
                    break;
                case 'greater_than':
                    $baseQuery->{$method}($queryItem['field'], '>', $queryItem['value']);
                    break;
                case 'less_than':
                    $baseQuery->{$method}($queryItem['field'], '<', $queryItem['value']);
                    break;
            }
        }

        $results = $baseQuery->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json(['data' => $results]);
    }
}
