<?php
// app/Http/Controllers/Api/ItemDraftController.php
namespace App\Http\Controllers\Api;

use App\Enums\ItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreItemDraftRequest;
use App\Http\Requests\UpdateItemDraftRequest;
use App\Models\ItemDraft;
use App\Services\DuplicateDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ItemDraftController extends Controller
{
    public function __construct()
    {
        /*$this->middleware('auth:sanctum');
        $this->middleware('throttle:authoring')->except(['index', 'show']);*/
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', ItemDraft::class);

        $query = ItemDraft::with(['concepts', 'tags', 'reviews'])
            ->when($request->status, fn($q) => $q->byStatus($request->status))
            ->when($request->item_type, fn($q) => $q->byType($request->item_type))
            ->when($request->created_by, fn($q) => $q->byAuthor($request->created_by))
            ->when($request->grade, fn($q) => $q->whereHas('concepts', fn($cq) => $cq->where('grade', $request->grade)))
            ->when($request->strand, fn($q) => $q->whereHas('concepts', fn($cq) => $cq->where('strand', $request->strand)))
            ->when($request->tag, fn($q) => $q->whereHas('tags', fn($tq) => $tq->where('code', $request->tag)))
            ->when($request->from_date, fn($q) => $q->where('created_at', '>=', $request->from_date))
            ->when($request->to_date, fn($q) => $q->where('created_at', '<=', $request->to_date));

        return response()->json([
            'data' => $query->latest()->paginate(20),
        ]);
    }

    public function store(StoreItemDraftRequest $request, DuplicateDetectionService $duplicateService)
    {
        $this->authorize('create', ItemDraft::class);

        $validated = $request->validated();
        $validated['created_by'] = auth()->id();
        $validated['updated_by'] = auth()->id();

        $draft = ItemDraft::create($validated);

        // Attach relationships
        if (!empty($validated['concept_ids'])) {
            $draft->concepts()->attach($validated['concept_ids']);
        }
        if (!empty($validated['tag_ids'])) {
            $draft->tags()->attach($validated['tag_ids']);
        }

        // Create options, hints, solutions
        foreach ($validated['options'] ?? [] as $index => $option) {
            $draft->options()->create([...$option, 'order_index' => $index]);
        }
        foreach ($validated['hints'] ?? [] as $index => $hint) {
            $draft->hints()->create([...$hint, 'order_index' => $index]);
        }
        foreach ($validated['solutions'] ?? [] as $solution) {
            $draft->solutions()->create($solution);
        }

        // Check for duplicates
        $duplicateService->generateContentHash($draft);
        $duplicates = $duplicateService->findSimilarContent($draft);

        return response()->json([
            'data' => $draft->load(['concepts', 'tags', 'options', 'hints', 'solutions']),
            'duplicates' => $duplicates,
        ], 201);
    }

    public function show(ItemDraft $itemDraft)
    {
        $this->authorize('view', $itemDraft);

        return response()->json([
            'data' => $itemDraft->load(['concepts', 'tags', 'options', 'hints', 'solutions', 'reviews.reviewer']),
        ]);
    }

    public function update(UpdateItemDraftRequest $request, ItemDraft $itemDraft)
    {
        $this->authorize('update', $itemDraft);

        $validated = $request->validated();
        $validated['updated_by'] = auth()->id();

        $itemDraft->update($validated);

        // Update relationships
        if (isset($validated['concept_ids'])) {
            $itemDraft->concepts()->sync($validated['concept_ids']);
        }
        if (isset($validated['tag_ids'])) {
            $itemDraft->tags()->sync($validated['tag_ids']);
        }

        return response()->json([
            'data' => $itemDraft->load(['concepts', 'tags', 'options', 'hints', 'solutions']),
        ]);
    }

    public function destroy(ItemDraft $itemDraft)
    {
        $this->authorize('delete', $itemDraft);

        $itemDraft->delete();

        return response()->json(['message' => 'Item draft deleted successfully']);
    }

    public function submitForReview(ItemDraft $itemDraft)
    {
        $this->authorize('submitForReview', $itemDraft);

        $itemDraft->update(['status' => ItemStatus::InReview]);

        return response()->json([
            'data' => $itemDraft,
            'message' => 'Item submitted for review',
        ]);
    }

    public function publish(ItemDraft $itemDraft)
    {
        $this->authorize('publish', $itemDraft);

        if (!$itemDraft->canBePublished()) {
            return response()->json([
                'error' => 'Item cannot be published in current status',
            ], 400);
        }

        $prod = $itemDraft->publish();

        return response()->json([
            'data' => $prod,
            'message' => 'Item published successfully',
        ]);
    }

    /**
     * Find duplicate or similar items
     */
    public function findDuplicates(Request $request, ItemDraft $itemDraft): JsonResponse
    {
        $request->validate([
            'threshold' => 'nullable|numeric|min:0|max:1',
            'include_exact' => 'nullable|boolean',
            'include_similar' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $threshold = $request->get('threshold', 0.8);
        $includeExact = $request->get('include_exact', true);
        $includeSimilar = $request->get('include_similar', true);
        $limit = $request->get('limit', 20);

        // Get or create content hash for the item
        $contentHash = $itemDraft->contentHash ?? ContentHash::createForItem($itemDraft);

        $results = [
            'item_id' => $itemDraft->id,
            'content_analysis' => $contentHash->getContentAnalysis(),
            'exact_duplicates' => collect(),
            'similar_items' => collect(),
            'summary' => [
                'total_found' => 0,
                'exact_count' => 0,
                'similar_count' => 0,
                'uniqueness_score' => $contentHash->uniqueness_score,
            ],
        ];

        // Find exact duplicates
        if ($includeExact) {
            $exactDuplicates = $contentHash->getExactDuplicates();
            $results['exact_duplicates'] = $exactDuplicates->take($limit)->map(function ($duplicate) {
                return [
                    'item_draft_id' => $duplicate->item_draft_id,
                    'similarity_score' => 1.0,
                    'match_type' => 'exact',
                    'item_preview' => [
                        'stem_ar' => Str::limit($duplicate->itemDraft->stem_ar ?? '', 100),
                        'item_type' => $duplicate->itemDraft->item_type,
                        'difficulty' => $duplicate->itemDraft->difficulty,
                        'status' => $duplicate->itemDraft->status,
                        'created_at' => $duplicate->itemDraft->created_at->toDateString(),
                        'created_by' => $duplicate->itemDraft->creator->name ?? 'غير معروف',
                    ],
                    'concepts' => $duplicate->itemDraft->concepts->pluck('name_ar'),
                    'tags' => $duplicate->itemDraft->tags->pluck('name_ar'),
                ];
            });
            $results['summary']['exact_count'] = $exactDuplicates->count();
        }

        // Find similar items
        if ($includeSimilar) {
            $similarItems = $contentHash->findSimilarItems($threshold);
            $results['similar_items'] = $similarItems->take($limit)->map(function ($similar) use ($itemDraft) {
                $item = $similar['content_hash']->itemDraft;
                return [
                    'item_draft_id' => $item->id,
                    'similarity_score' => round($similar['similarity_score'], 3),
                    'match_type' => 'similar',
                    'item_preview' => [
                        'stem_ar' => Str::limit($item->stem_ar ?? '', 100),
                        'item_type' => $item->item_type,
                        'difficulty' => $item->difficulty,
                        'status' => $item->status,
                        'created_at' => $item->created_at->toDateString(),
                        'created_by' => $item->creator->name ?? 'غير معروف',
                    ],
                    'concepts' => $item->concepts->pluck('name_ar'),
                    'tags' => $item->tags->pluck('name_ar'),
                    'similarity_details' => [
                        'common_tokens' => array_intersect(
                            $contentHash->similarity_tokens ?? [],
                            $similar['content_hash']->similarity_tokens ?? []
                        ),
                        'content_overlap' => $this->calculateContentOverlap($itemDraft->stem_ar, $item->stem_ar),
                    ],
                ];
            });
            $results['summary']['similar_count'] = $similarItems->count();
        }

        $results['summary']['total_found'] = $results['summary']['exact_count'] + $results['summary']['similar_count'];

        // Add recommendations
        $results['recommendations'] = $this->getDuplicateRecommendations($results['summary']);

        return response()->json(['data' => $results]);
    }

    private function calculateContentOverlap(string $content1, string $content2): array
    {
        $words1 = array_unique(explode(' ', ContentHash::normalizeContent($content1)));
        $words2 = array_unique(explode(' ', ContentHash::normalizeContent($content2)));

        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        return [
            'common_words' => count($intersection),
            'total_unique_words' => count($union),
            'overlap_percentage' => count($union) > 0 ? round(count($intersection) / count($union) * 100, 1) : 0,
        ];
    }

    private function getDuplicateRecommendations(array $summary): array
    {
        $recommendations = [];

        if ($summary['exact_count'] > 0) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => "تم العثور على {$summary['exact_count']} عنصر مطابق تماماً. ننصح بمراجعة هذه العناصر لتجنب التكرار.",
                'action' => 'review_exact_duplicates',
            ];
        }

        if ($summary['similar_count'] > 5) {
            $recommendations[] = [
                'type' => 'info',
                'message' => "تم العثور على {$summary['similar_count']} عنصر مشابه. قد ترغب في تعديل المحتوى لجعله أكثر تميزاً.",
                'action' => 'review_similar_items',
            ];
        }

        if ($summary['uniqueness_score'] < 50) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => "معدل التفرد منخفض ({$summary['uniqueness_score']}%). ننصح بإعادة صياغة المحتوى ليكون أكثر تميزاً.",
                'action' => 'improve_uniqueness',
            ];
        }

        return $recommendations;
    }
}
