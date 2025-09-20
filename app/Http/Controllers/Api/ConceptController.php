<?php
// app/Http/Controllers/Api/ConceptController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\{StoreConceptRequest, UpdateConceptRequest};
use App\Models\{Concept, AuditLog};
use Illuminate\Http\{Request, JsonResponse};

class ConceptController extends Controller
{
    public function __construct()
    {
        /*$this->middleware('auth:sanctum');
        $this->middleware('can:manage-concepts')->except(['index', 'show']);*/
    }

    /**
     * List concepts with filtering and hierarchy
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'grade' => 'nullable|string',
            'strand' => 'nullable|string',
            'search' => 'nullable|string|max:200',
            'with_item_counts' => 'nullable|boolean',
            'hierarchy' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Concept::query()
            ->when($request->grade, fn($q) => $q->byGrade($request->grade))
            ->when($request->strand, fn($q) => $q->byStrand($request->strand))
            ->when($request->search, function($q) use ($request) {
                $q->where(function($subQ) use ($request) {
                    $subQ->where('name_ar', 'LIKE', "%{$request->search}%")
                        ->orWhere('name_en', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%");
                });
            });

        if ($request->get('with_item_counts', false)) {
            $query->withCount(['itemDrafts', 'itemProds']);
        }

        if ($request->get('hierarchy', false)) {
            // Return hierarchical structure
            $concepts = $query->get();
            $hierarchical = $this->buildHierarchy($concepts);

            return response()->json([
                'data' => $hierarchical,
                'metadata' => [
                    'total_concepts' => $concepts->count(),
                    'structure' => 'hierarchical',
                ],
            ]);
        }

        $concepts = $query->orderBy('grade')
            ->orderBy('strand')
            ->orderBy('name_ar')
            ->paginate($request->get('per_page', 15));

        return response()->json(['data' => $concepts]);
    }

    /**
     * Store new concept
     */
    public function store(StoreConceptRequest $request): JsonResponse
    {
        $concept = Concept::create($request->validated());

        // Log creation
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'created',
            'auditable_type' => Concept::class,
            'auditable_id' => $concept->id,
            'new_values' => $concept->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => $concept->fresh(),
            'message' => 'تم إنشاء المفهوم بنجاح',
        ], 201);
    }

    /**
     * Show specific concept with related items
     */
    public function show(Concept $concept): JsonResponse
    {
        $concept->load([
            'itemDrafts' => fn($q) => $q->with(['creator', 'tags'])->latest()->take(5),
            'itemProds' => fn($q) => $q->with(['publisher', 'tags'])->latest()->take(5),
        ]);

        // Add statistics
        $concept->statistics = [
            'total_draft_items' => $concept->itemDrafts()->count(),
            'total_published_items' => $concept->itemProds()->count(),
            'difficulty_distribution' => $concept->itemProds()
                ->selectRaw('
                    CASE
                        WHEN difficulty <= 2 THEN "easy"
                        WHEN difficulty <= 3.5 THEN "medium"
                        ELSE "hard"
                    END as difficulty_level,
                    COUNT(*) as count
                ')
                ->groupBy('difficulty_level')
                ->pluck('count', 'difficulty_level'),
            'item_types' => $concept->itemProds()
                ->selectRaw('item_type, COUNT(*) as count')
                ->groupBy('item_type')
                ->pluck('count', 'item_type'),
        ];

        // Add related concepts
        $concept->related_concepts = $concept->getRelatedConcepts();

        return response()->json(['data' => $concept]);
    }

    /**
     * Update concept
     */
    public function update(UpdateConceptRequest $request, Concept $concept): JsonResponse
    {
        $oldData = $concept->toArray();
        $concept->update($request->validated());

        // Log update
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'updated',
            'auditable_type' => Concept::class,
            'auditable_id' => $concept->id,
            'old_values' => $oldData,
            'new_values' => $concept->fresh()->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => $concept->fresh(),
            'message' => 'تم تحديث المفهوم بنجاح',
        ]);
    }

    /**
     * Delete concept
     */
    public function destroy(Concept $concept): JsonResponse
    {
        // Check if concept is being used
        $itemCount = $concept->itemDrafts()->count() + $concept->itemProds()->count();

        if ($itemCount > 0) {
            return response()->json([
                'error' => 'لا يمكن حذف المفهوم لأنه مرتبط بـ ' . $itemCount . ' عنصر',
            ], 400);
        }

        $conceptData = $concept->toArray();
        $concept->delete();

        // Log deletion
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'deleted',
            'auditable_type' => Concept::class,
            'auditable_id' => $concept->id,
            'old_values' => $conceptData,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json(['message' => 'تم حذف المفهوم بنجاح']);
    }

    /**
     * Get curriculum structure
     */
    public function curriculum(): JsonResponse
    {
        $structure = Concept::getCurriculumStructure();

        return response()->json([
            'data' => $structure,
            'metadata' => [
                'total_concepts' => Concept::count(),
                'grades' => Concept::getGradeOptions(),
                'strands' => Concept::getStrandOptions(),
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Get concept usage statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'grade' => 'nullable|string',
            'strand' => 'nullable|string',
        ]);

        $query = Concept::withCount(['itemDrafts', 'itemProds'])
            ->when($request->grade, fn($q) => $q->where('grade', $request->grade))
            ->when($request->strand, fn($q) => $q->where('strand', $request->strand));

        $concepts = $query->get();

        $stats = [
            'overview' => [
                'total_concepts' => $concepts->count(),
                'concepts_with_items' => $concepts->where('item_prods_count', '>', 0)->count(),
                'avg_items_per_concept' => round($concepts->avg('item_prods_count'), 2),
            ],
            'most_used' => $concepts->sortByDesc('item_prods_count')->take(10)->values(),
            'underutilized' => $concepts->where('item_prods_count', '<=', 2)->sortBy('item_prods_count')->take(10)->values(),
            'by_grade' => $concepts->groupBy('grade')->map(function ($gradeConcepts) {
                return [
                    'concept_count' => $gradeConcepts->count(),
                    'total_items' => $gradeConcepts->sum('item_prods_count'),
                    'avg_items' => round($gradeConcepts->avg('item_prods_count'), 2),
                ];
            }),
            'by_strand' => $concepts->groupBy('strand')->map(function ($strandConcepts) {
                return [
                    'concept_count' => $strandConcepts->count(),
                    'total_items' => $strandConcepts->sum('item_prods_count'),
                    'avg_items' => round($strandConcepts->avg('item_prods_count'), 2),
                ];
            }),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Bulk import concepts
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate([
            'concepts' => 'required|array|min:1|max:100',
            'concepts.*.code' => 'required|string|unique:concepts,code',
            'concepts.*.name_ar' => 'required|string|max:255',
            'concepts.*.name_en' => 'nullable|string|max:255',
            'concepts.*.grade' => 'required|string',
            'concepts.*.strand' => 'required|string',
            'concepts.*.description_ar' => 'nullable|string',
        ]);

        $imported = [];
        $errors = [];

        foreach ($request->concepts as $index => $conceptData) {
            try {
                $concept = Concept::create($conceptData);
                $imported[] = $concept;

                // Log import
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'bulk_imported',
                    'auditable_type' => Concept::class,
                    'auditable_id' => $concept->id,
                    'new_values' => $concept->toArray(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'data' => $conceptData,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => [
                'imported' => $imported,
                'errors' => $errors,
            ],
            'summary' => [
                'total_submitted' => count($request->concepts),
                'imported_count' => count($imported),
                'error_count' => count($errors),
            ],
            'message' => 'تم استيراد ' . count($imported) . ' مفهوم من أصل ' . count($request->concepts),
        ], 201);
    }

    /**
     * Search concepts with suggestions
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:100',
            'limit' => 'nullable|integer|min:1|max:20',
            'include_suggestions' => 'nullable|boolean',
        ]);

        $query = $request->get('q');
        $limit = $request->get('limit', 10);
        $includeSuggestions = $request->get('include_suggestions', false);

        // Search concepts
        $concepts = Concept::where(function($q) use ($query) {
            $q->where('name_ar', 'LIKE', "%{$query}%")
                ->orWhere('name_en', 'LIKE', "%{$query}%")
                ->orWhere('code', 'LIKE', "%{$query}%");
        })
            ->withCount('itemProds')
            ->orderBy('item_prods_count', 'desc')
            ->limit($limit)
            ->get();

        $response = ['data' => $concepts];

        if ($includeSuggestions && $concepts->isNotEmpty()) {
            $response['suggestions'] = $concepts->first()->generateSuggestions();
        }

        return response()->json($response);
    }

    private function buildHierarchy($concepts): array
    {
        return $concepts->groupBy('grade')->map(function ($gradeConcepts, $grade) {
            return [
                'grade' => $grade,
                'strands' => $gradeConcepts->groupBy('strand')->map(function ($strandConcepts, $strand) {
                    return [
                        'strand' => $strand,
                        'concepts' => $strandConcepts->values(),
                    ];
                })->values(),
            ];
        })->values()->toArray();
    }
}
