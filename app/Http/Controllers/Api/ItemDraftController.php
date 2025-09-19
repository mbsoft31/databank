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

class ItemDraftController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('throttle:authoring')->except(['index', 'show']);
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
}
