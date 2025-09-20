<?php
// app/Http/Controllers/Api/MediaController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Storage, Validator};
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function __construct()
    {
        /*$this->middleware('auth:sanctum');
        $this->middleware('throttle:storage')->except(['index', 'show']);*/
    }

    /**
     * List media assets
     */
    public function index(Request $request)
    {
        $request->validate([
            'type' => 'nullable|string|in:image,video,audio,document',
            'search' => 'nullable|string|max:100',
            'created_by' => 'nullable|string',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'sort_by' => 'nullable|string|in:filename,size_bytes,created_at',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'integer|min:1|max:100',
        ]);

        $query = MediaAsset::query();

        // Filter by media type
        if ($request->type) {
            $mimeTypePattern = match($request->type) {
                'image' => 'image/%',
                'video' => 'video/%',
                'audio' => 'audio/%',
                'document' => 'application/%',
            };
            $query->where('mime_type', 'LIKE', $mimeTypePattern);
        }

        // Search by filename
        if ($request->search) {
            $query->where('filename', 'LIKE', '%' . $request->search . '%');
        }

        // Filter by creator (if user has permission)
        if ($request->created_by && (auth()->user()->is_admin || auth()->id() === $request->created_by)) {
            $query->where('created_by', $request->created_by);
        } elseif (!auth()->user()->is_admin) {
            $query->where('created_by', auth()->id());
        }

        // Date filters
        $query->when($request->from_date, fn($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date, fn($q) => $q->whereDate('created_at', '<=', $request->to_date));

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $media = $query->paginate($request->get('per_page', 20));

        // Add download URLs and usage info
        $media->getCollection()->transform(function ($asset) {
            $asset->download_url = $this->generateDownloadUrl($asset);
            $asset->thumbnail_url = $this->generateThumbnailUrl($asset);
            $asset->usage_count = $this->getUsageCount($asset);
            $asset->is_used = $asset->usage_count > 0;
            return $asset;
        });

        return response()->json(['data' => $media]);
    }

    /**
     * Generate presigned URL for direct upload to S3
     */
    public function getPresignedUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'filename' => 'required|string|max:255',
            'mime_type' => 'required|string|max:100',
            'size_bytes' => 'required|integer|max:104857600', // 100MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Validate file type
        if (!$this->isAllowedMimeType($request->mime_type)) {
            return response()->json([
                'error' => 'File type not allowed',
                'allowed_types' => $this->getAllowedMimeTypes()
            ], 400);
        }

        // Check user quota (100MB per user, 1GB for admins)
        $userLimit = auth()->user()->is_admin ? 1073741824 : 104857600; // 1GB : 100MB
        $currentUsage = MediaAsset::where('created_by', auth()->id())->sum('size_bytes');

        if ($currentUsage + $request->size_bytes > $userLimit) {
            return response()->json([
                'error' => 'Storage quota exceeded',
                'current_usage_mb' => round($currentUsage / 1048576, 2),
                'limit_mb' => round($userLimit / 1048576, 2),
            ], 400);
        }

        // Generate unique storage path
        $extension = pathinfo($request->filename, PATHINFO_EXTENSION);
        $uniqueId = Str::uuid();
        $storagePath = "media/" . date('Y/m') . "/{$uniqueId}.{$extension}";

        try {
            // Generate presigned URL for S3 upload
            $disk = Storage::disk(config('filesystems.default'));
            $presignedUrl = $disk->temporaryUrl($storagePath, now()->addMinutes(15), [
                'ResponseContentType' => $request->mime_type,
                'ResponseContentDisposition' => 'attachment; filename="' . $request->filename . '"',
            ]);

            // Create pending media asset record
            $mediaAsset = MediaAsset::create([
                'filename' => $request->filename,
                'mime_type' => $request->mime_type,
                'size_bytes' => $request->size_bytes,
                'storage_path' => $storagePath,
                'created_by' => auth()->id(),
                'meta' => [
                    'upload_status' => 'pending',
                    'upload_expires_at' => now()->addMinutes(15)->toISOString(),
                ],
            ]);

            return response()->json([
                'upload_url' => $presignedUrl,
                'media_asset_id' => $mediaAsset->id,
                'expires_at' => now()->addMinutes(15)->toISOString(),
                'max_file_size' => $userLimit,
                'current_usage' => $currentUsage,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate upload URL',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm successful upload and finalize media asset
     */
    public function confirmUpload(Request $request)
    {
        $request->validate([
            'media_asset_id' => 'required|uuid|exists:media_assets,id',
            'checksum' => 'nullable|string', // For file integrity verification
        ]);

        $mediaAsset = MediaAsset::findOrFail($request->media_asset_id);

        // Check ownership
        if ($mediaAsset->created_by !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        // Check if upload is still pending
        if ($mediaAsset->meta['upload_status'] !== 'pending') {
            return response()->json(['error' => 'Upload already confirmed or expired'], 400);
        }

        try {
            // Verify file exists in storage
            $disk = Storage::disk(config('filesystems.default'));
            if (!$disk->exists($mediaAsset->storage_path)) {
                return response()->json(['error' => 'File not found in storage'], 400);
            }

            // Verify file size matches
            $actualSize = $disk->size($mediaAsset->storage_path);
            if (abs($actualSize - $mediaAsset->size_bytes) > 1024) { // Allow 1KB difference
                return response()->json([
                    'error' => 'File size mismatch',
                    'expected' => $mediaAsset->size_bytes,
                    'actual' => $actualSize
                ], 400);
            }

            // Update file size to actual size
            $mediaAsset->size_bytes = $actualSize;

            // Generate additional metadata based on file type
            $metadata = $this->extractFileMetadata($mediaAsset);

            // Update media asset as confirmed
            $mediaAsset->update([
                'meta' => array_merge($mediaAsset->meta ?? [], $metadata, [
                    'upload_status' => 'confirmed',
                    'confirmed_at' => now()->toISOString(),
                    'file_hash' => $request->checksum,
                ])
            ]);

            // Generate response with URLs
            $response = [
                'data' => $mediaAsset,
                'download_url' => $this->generateDownloadUrl($mediaAsset),
            ];

            // Add thumbnail for images
            if (str_starts_with($mediaAsset->mime_type, 'image/')) {
                $response['thumbnail_url'] = $this->generateThumbnailUrl($mediaAsset);
            }

            return response()->json($response);

        } catch (\Exception $e) {
            // Mark as failed and clean up
            $mediaAsset->update([
                'meta' => array_merge($mediaAsset->meta ?? [], [
                    'upload_status' => 'failed',
                    'error' => $e->getMessage(),
                ])
            ]);

            return response()->json([
                'error' => 'Upload confirmation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get media asset details
     */
    public function show(MediaAsset $mediaAsset)
    {
        // Check access permissions
        if (!auth()->user()->is_admin && $mediaAsset->created_by !== auth()->id()) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $mediaAsset->download_url = $this->generateDownloadUrl($mediaAsset);
        $mediaAsset->thumbnail_url = $this->generateThumbnailUrl($mediaAsset);
        $mediaAsset->usage_count = $this->getUsageCount($mediaAsset);
        $mediaAsset->used_in = $this->getUsageDetails($mediaAsset);

        return response()->json(['data' => $mediaAsset]);
    }

    /**
     * Download media file
     */
    public function download(MediaAsset $mediaAsset)
    {
        // Check access permissions
        if (!auth()->user()->is_admin && $mediaAsset->created_by !== auth()->id()) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $disk = Storage::disk(config('filesystems.default'));

        if (!$disk->exists($mediaAsset->storage_path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return $disk->download($mediaAsset->storage_path, $mediaAsset->filename);
    }

    /**
     * Delete media asset
     */
    public function destroy( MediaAsset $mediaAsset)
    {
        // Check ownership
        if ($mediaAsset->created_by !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        // Check if file is being used
        $usageCount = $this->getUsageCount($mediaAsset);
        if ($usageCount > 0 && !request()->get('force', false)) {
            return response()->json([
                'error' => 'File is currently being used',
                'usage_count' => $usageCount,
                'message' => 'Add ?force=true to delete anyway'
            ], 400);
        }

        try {
            // Delete file from storage
            $disk = Storage::disk(config('filesystems.default'));
            if ($disk->exists($mediaAsset->storage_path)) {
                $disk->delete($mediaAsset->storage_path);
            }

            // Delete thumbnails if they exist
            $this->deleteThumbnails($mediaAsset);

            // Delete database record
            $mediaAsset->delete();

            return response()->json(['message' => 'Media asset deleted successfully']);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete media asset',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete media assets
     */
    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'media_asset_ids' => 'required|array|max:50',
            'media_asset_ids.*' => 'uuid|exists:media_assets,id',
            'force' => 'nullable|boolean',
        ]);

        $mediaAssets = MediaAsset::whereIn('id', $request->media_asset_ids)
            ->where(function($query) {
                $query->where('created_by', auth()->id())
                    ->orWhere(fn($q) => auth()->user()->is_admin);
            })
            ->get();

        if ($mediaAssets->count() !== count($request->media_asset_ids)) {
            return response()->json(['error' => 'Some assets not found or access denied'], 403);
        }

        $deleted = 0;
        $errors = [];
        $force = $request->get('force', false);

        foreach ($mediaAssets as $asset) {
            try {
                $usageCount = $this->getUsageCount($asset);
                if ($usageCount > 0 && !$force) {
                    $errors[] = [
                        'id' => $asset->id,
                        'filename' => $asset->filename,
                        'error' => 'File is being used',
                        'usage_count' => $usageCount
                    ];
                    continue;
                }

                // Delete from storage
                $disk = Storage::disk(config('filesystems.default'));
                if ($disk->exists($asset->storage_path)) {
                    $disk->delete($asset->storage_path);
                }

                $this->deleteThumbnails($asset);
                $asset->delete();
                $deleted++;

            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $asset->id,
                    'filename' => $asset->filename,
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'message' => "Successfully deleted {$deleted} media assets",
            'deleted_count' => $deleted,
            'errors' => $errors,
        ]);
    }

    /**
     * Get storage usage statistics
     */
    public function storageStats()
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->is_admin;

        $query = MediaAsset::query();
        if (!$isAdmin) {
            $query->where('created_by', $userId);
        }

        $stats = [
            'total_files' => $query->count(),
            'total_size_bytes' => $query->sum('size_bytes'),
            'total_size_mb' => round($query->sum('size_bytes') / 1048576, 2),
            'files_by_type' => $query->selectRaw('
                    CASE
                        WHEN mime_type LIKE "image/%" THEN "Images"
                        WHEN mime_type LIKE "video/%" THEN "Videos"
                        WHEN mime_type LIKE "audio/%" THEN "Audio"
                        ELSE "Documents"
                    END as media_type,
                    COUNT(*) as count,
                    SUM(size_bytes) as total_size
                ')
                ->groupBy('media_type')
                ->get(),
            'recent_uploads' => $query->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(['id', 'filename', 'mime_type', 'size_bytes', 'created_at']),
        ];

        // Add quota information for non-admins
        if (!$isAdmin) {
            $limit = 104857600; // 100MB
            $stats['quota'] = [
                'used_bytes' => $stats['total_size_bytes'],
                'used_mb' => $stats['total_size_mb'],
                'limit_bytes' => $limit,
                'limit_mb' => 100,
                'usage_percentage' => round(($stats['total_size_bytes'] / $limit) * 100, 1),
                'remaining_mb' => round(($limit - $stats['total_size_bytes']) / 1048576, 2),
            ];
        }

        return response()->json(['data' => $stats]);
    }

    /**
     * Clean up orphaned media files
     */
    public function cleanup()
    {
        $this->authorize('admin-access');

        // Find media assets not used by any items
        $orphanedAssets = MediaAsset::whereDoesntHave('itemDrafts')
            ->whereDoesntHave('itemProds')
            ->where('created_at', '<', now()->subDays(30)) // Only old files
            ->get();

        $deleted = 0;
        $totalSize = 0;

        foreach ($orphanedAssets as $asset) {
            try {
                $disk = Storage::disk(config('filesystems.default'));
                if ($disk->exists($asset->storage_path)) {
                    $disk->delete($asset->storage_path);
                }

                $this->deleteThumbnails($asset);
                $totalSize += $asset->size_bytes;
                $asset->delete();
                $deleted++;

            } catch (\Exception $e) {
                // Log error but continue
                logger("Failed to delete orphaned media {$asset->id}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => "Cleaned up {$deleted} orphaned media files",
            'deleted_count' => $deleted,
            'space_freed_mb' => round($totalSize / 1048576, 2),
        ]);
    }

    // Helper methods

    private function isAllowedMimeType(string $mimeType): bool
    {
        $allowed = [
            // Images
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            // Videos
            'video/mp4', 'video/webm', 'video/quicktime',
            // Audio
            'audio/mpeg', 'audio/wav', 'audio/ogg',
            // Documents
            'application/pdf', 'text/plain', 'application/json',
        ];

        return in_array($mimeType, $allowed);
    }

    private function getAllowedMimeTypes(): array
    {
        return [
            'images' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
            'videos' => ['video/mp4', 'video/webm', 'video/quicktime'],
            'audio' => ['audio/mpeg', 'audio/wav', 'audio/ogg'],
            'documents' => ['application/pdf', 'text/plain', 'application/json'],
        ];
    }

    private function generateDownloadUrl(MediaAsset $asset): string
    {
        return route('api.media.download', $asset);
    }

    private function generateThumbnailUrl(MediaAsset $asset): ?string
    {
        if (!str_starts_with($asset->mime_type, 'image/')) {
            return null;
        }

        // Generate thumbnail path
        $pathinfo = pathinfo($asset->storage_path);
        $thumbnailPath = $pathinfo['dirname'] . '/thumbs/' . $pathinfo['filename'] . '_thumb.' . $pathinfo['extension'];

        return Storage::disk(config('filesystems.default'))->url($thumbnailPath);
    }

    private function getUsageCount(MediaAsset $asset): int
    {
        // This would need to be implemented based on how media is referenced
        // For now, return 0 as placeholder
        return 0;
    }

    private function getUsageDetails(MediaAsset $asset): array
    {
        // Return details about where this media is used
        return [
            'item_drafts' => [],
            'item_prods' => [],
        ];
    }

    private function extractFileMetadata(MediaAsset $asset): array
    {
        $metadata = [];

        if (str_starts_with($asset->mime_type, 'image/')) {
            // For images, could extract dimensions, EXIF data, etc.
            $metadata['type'] = 'image';
        } elseif (str_starts_with($asset->mime_type, 'video/')) {
            $metadata['type'] = 'video';
        } elseif (str_starts_with($asset->mime_type, 'audio/')) {
            $metadata['type'] = 'audio';
        } else {
            $metadata['type'] = 'document';
        }

        return $metadata;
    }

    private function deleteThumbnails(MediaAsset $asset): void
    {
        if (!str_starts_with($asset->mime_type, 'image/')) {
            return;
        }

        $disk = Storage::disk(config('filesystems.default'));
        $pathinfo = pathinfo($asset->storage_path);
        $thumbnailPath = $pathinfo['dirname'] . '/thumbs/' . $pathinfo['filename'] . '_thumb.' . $pathinfo['extension'];

        if ($disk->exists($thumbnailPath)) {
            $disk->delete($thumbnailPath);
        }
    }
}
