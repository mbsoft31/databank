<?php
// app/Http/Controllers/Api/ExportController.php
namespace App\Http\Controllers\Api;

use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateExportJob;
use App\Models\{Export, ItemProd};
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    public function __construct()
    {
        /*$this->middleware('auth:sanctum');
        $this->middleware('throttle:storage')->only(['store']);*/
    }

    /**
     * List user's exports
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|string|in:pending,processing,completed,failed',
            'kind' => 'nullable|string|in:worksheet_markdown,worksheet_html,worksheet_pdf',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $query = Export::where('requested_by', auth()->id())
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->kind, fn($q) => $q->where('kind', $request->kind))
            ->orderBy('created_at', 'desc');

        $exports = $query->paginate($request->get('per_page', 15));

        // Add download URLs for completed exports
        $exports->getCollection()->transform(function ($export) {
            if ($export->status === ExportStatus::Completed && $export->file_path) {
                $export->download_url = route('api.exports.download', $export);
            }
            return $export;
        });

        return response()->json(['data' => $exports]);
    }

    /**
     * Create new export job
     */
    public function store(Request $request)
    {
        $request->validate([
            'kind' => 'required|string|in:worksheet_markdown,worksheet_html,worksheet_pdf',
            'params' => 'required|array',
            'params.item_ids' => 'required|array|min:1|max:' . config('services.exports.max_items_per_export', 50),
            'params.item_ids.*' => 'uuid|exists:item_prod,id',
            'params.title' => 'nullable|string|max:200',
            'params.subtitle' => 'nullable|string|max:200',
            'params.include_solutions' => 'nullable|boolean',
            'params.include_hints' => 'nullable|boolean',
            'params.randomize_order' => 'nullable|boolean',
            'params.page_layout' => 'nullable|string|in:single_column,two_column',
            'params.font_size' => 'nullable|string|in:small,medium,large',
            'params.header_text' => 'nullable|string|max:100',
            'params.footer_text' => 'nullable|string|max:100',
        ]);

        // Verify user can access all requested items
        $itemIds = $request->input('params.item_ids');
        $accessibleItemsCount = ItemProd::whereIn('id', $itemIds)->count();

        if ($accessibleItemsCount !== count($itemIds)) {
            return response()->json([
                'error' => 'Some requested items are not accessible or do not exist.'
            ], 403);
        }

        // Check user's export quota (10 per day for regular users, unlimited for admins)
        if (!auth()->user()->is_admin) {
            $todayExportsCount = Export::where('requested_by', auth()->id())
                ->whereDate('created_at', today())
                ->count();

            if ($todayExportsCount >= 10) {
                return response()->json([
                    'error' => 'Daily export limit reached. Please try again tomorrow.'
                ], 429);
            }
        }

        $export = Export::create([
            'kind' => $request->kind,
            'params' => $request->params,
            'status' => ExportStatus::Pending,
            'requested_by' => auth()->id(),
        ]);

        // Queue the export job
        GenerateExportJob::dispatch($export)->onQueue('exports');

        return response()->json([
            'data' => $export,
            'message' => 'Export job queued successfully. You will be notified when it\'s ready.'
        ], 201);
    }

    /**
     * Get export details
     */
    public function show(Export $export)
    {
        // Check ownership
        if ($export->requested_by !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        // Add download URL if completed
        if ($export->status === ExportStatus::Completed && $export->file_path) {
            $export->download_url = route('api.exports.download', $export);
        }

        // Add progress information
        $export->estimated_completion = $this->getEstimatedCompletion($export);

        return response()->json(['data' => $export]);
    }

    /**
     * Download completed export
     */
    public function download(Export $export)
    {
        // Check ownership
        if ($export->requested_by !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        if ($export->status !== ExportStatus::Completed || !$export->file_path) {
            return response()->json(['error' => 'Export not ready for download.'], 400);
        }

        if (!Storage::disk(config('services.exports.storage_disk', 'local'))->exists($export->file_path)) {
            return response()->json(['error' => 'Export file not found.'], 404);
        }

        $filename = $this->generateDownloadFilename($export);

        return Storage::disk(config('services.exports.storage_disk', 'local'))
            ->download($export->file_path, $filename);
    }

    /**
     * Cancel pending export
     */
    public function cancel(Export $export)
    {
        // Check ownership
        if ($export->requested_by !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        if (!in_array($export->status, [ExportStatus::Pending, ExportStatus::Processing])) {
            return response()->json(['error' => 'Cannot cancel completed or failed export.'], 400);
        }

        $export->update([
            'status' => ExportStatus::Failed,
            'error_message' => 'Cancelled by user',
        ]);

        return response()->json(['message' => 'Export cancelled successfully.']);
    }

    /**
     * Delete export and its files
     */
    public function destroy(Export $export)
    {
        // Check ownership
        if ($export->requested_by !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        // Delete file if exists
        if ($export->file_path) {
            Storage::disk(config('services.exports.storage_disk', 'local'))
                ->delete($export->file_path);
        }

        $export->delete();

        return response()->json(['message' => 'Export deleted successfully.']);
    }

    /**
     * Bulk delete exports
     */
    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'export_ids' => 'required|array|max:50',
            'export_ids.*' => 'uuid|exists:exports,id',
        ]);

        $exports = Export::whereIn('id', $request->export_ids)
            ->where('requested_by', auth()->id())
            ->get();

        if ($exports->count() !== count($request->export_ids)) {
            return response()->json(['error' => 'Some exports not found or access denied.'], 403);
        }

        foreach ($exports as $export) {
            if ($export->file_path) {
                Storage::disk(config('services.exports.storage_disk', 'local'))
                    ->delete($export->file_path);
            }
            $export->delete();
        }

        return response()->json([
            'message' => "Successfully deleted {$exports->count()} exports."
        ]);
    }

    /**
     * Get export templates/presets
     */
    public function templates()
    {
        $templates = [
            [
                'id' => 'basic_worksheet',
                'name' => 'Basic Worksheet',
                'description' => 'Simple worksheet with questions only',
                'params' => [
                    'include_solutions' => false,
                    'include_hints' => false,
                    'page_layout' => 'single_column',
                    'font_size' => 'medium',
                ]
            ],
            [
                'id' => 'detailed_worksheet',
                'name' => 'Detailed Worksheet',
                'description' => 'Worksheet with hints and solutions',
                'params' => [
                    'include_solutions' => true,
                    'include_hints' => true,
                    'page_layout' => 'two_column',
                    'font_size' => 'medium',
                ]
            ],
            [
                'id' => 'exam_format',
                'name' => 'Exam Format',
                'description' => 'Clean exam-style layout',
                'params' => [
                    'include_solutions' => false,
                    'include_hints' => false,
                    'page_layout' => 'single_column',
                    'font_size' => 'large',
                    'randomize_order' => true,
                ]
            ],
        ];

        return response()->json(['data' => $templates]);
    }

    /**
     * Preview export without generating file
     */
    public function preview(Request $request)
    {
        $request->validate([
            'kind' => 'required|string|in:worksheet_html',
            'params' => 'required|array',
            'params.item_ids' => 'required|array|min:1|max:5', // Limited for preview
            'params.item_ids.*' => 'uuid|exists:item_prod,id',
        ]);

        $items = ItemProd::with(['concepts', 'tags', 'options', 'hints', 'solutions'])
            ->whereIn('id', $request->input('params.item_ids'))
            ->get();

        $previewHtml = view('exports.preview', [
            'items' => $items,
            'params' => $request->params,
        ])->render();

        return response()->json([
            'data' => [
                'html' => $previewHtml,
                'item_count' => $items->count(),
            ]
        ]);
    }

    /**
     * Get export statistics
     */
    public function statistics()
    {
        $userId = auth()->id();

        $stats = [
            'total_exports' => Export::where('requested_by', $userId)->count(),
            'completed_exports' => Export::where('requested_by', $userId)
                ->where('status', ExportStatus::Completed)->count(),
            'failed_exports' => Export::where('requested_by', $userId)
                ->where('status', ExportStatus::Failed)->count(),
            'exports_this_month' => Export::where('requested_by', $userId)
                ->whereMonth('created_at', now()->month)->count(),
            'favorite_format' => Export::where('requested_by', $userId)
                ->selectRaw('kind, COUNT(*) as count')
                ->groupBy('kind')
                ->orderBy('count', 'desc')
                ->first()?->kind,
            'total_items_exported' => Export::where('requested_by', $userId)
                ->where('status', ExportStatus::Completed)
                ->get()
                ->sum(function ($export) {
                    return count($export->params['item_ids'] ?? []);
                }),
        ];

        return response()->json(['data' => $stats]);
    }

    private function getEstimatedCompletion(Export $export): ?string
    {
        if ($export->status === ExportStatus::Completed) {
            return null;
        }

        if ($export->status === ExportStatus::Processing) {
            $itemCount = count($export->params['item_ids'] ?? []);
            $estimatedSeconds = $itemCount * 2; // 2 seconds per item
            return now()->addSeconds($estimatedSeconds)->toISOString();
        }

        // For pending exports, estimate based on queue position
        $queuePosition = Export::where('status', ExportStatus::Pending)
            ->where('created_at', '<', $export->created_at)
            ->count();

        $estimatedSeconds = $queuePosition * 30; // 30 seconds per job ahead
        return now()->addSeconds($estimatedSeconds)->toISOString();
    }

    private function generateDownloadFilename(Export $export): string
    {
        $title = $export->params['title'] ?? 'worksheet';
        $title = Str::slug($title);

        $extension = match($export->kind) {
            'worksheet_pdf' => 'pdf',
            'worksheet_html' => 'html',
            'worksheet_markdown' => 'md',
            default => 'txt'
        };

        return "{$title}_{$export->created_at->format('Y-m-d')}.{$extension}";
    }
}
