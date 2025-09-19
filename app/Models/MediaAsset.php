<?php
// app/Models/MediaAsset.php
namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'filename',
        'mime_type',
        'size_bytes',
        'storage_path',
        'meta',
        'created_by',
    ];

    protected $casts = [
        'meta' => 'array',
        'size_bytes' => 'integer',
    ];

    protected $appends = [
        'size_formatted',
        'media_type',
        'is_image',
        'is_video',
        'is_audio',
        'file_extension',
    ];

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function itemDrafts(): BelongsToMany
    {
        return $this->belongsToMany(ItemDraft::class, 'item_draft_media');
    }

    public function itemProds(): BelongsToMany
    {
        return $this->belongsToMany(ItemProd::class, 'item_prod_media');
    }

    // Scopes
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    public function scopeVideos(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'video/%');
    }

    public function scopeAudio(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'audio/%');
    }

    public function scopeDocuments(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'application/%');
    }

    public function scopeByCreator(Builder $query, string $userId): Builder
    {
        return $query->where('created_by', $userId);
    }

    public function scopeOrphaned(Builder $query): Builder
    {
        return $query->whereDoesntHave('itemDrafts')
            ->whereDoesntHave('itemProds');
    }

    public function scopeLargeFiles(Builder $query, int $minSizeBytes = 10485760): Builder // 10MB
    {
        return $query->where('size_bytes', '>', $minSizeBytes);
    }

    // Accessors
    public function getSizeFormattedAttribute(): string
    {
        $bytes = $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getMediaTypeAttribute(): string
    {
        $mimeType = $this->mime_type;

        if (str_starts_with($mimeType, 'image/')) return 'image';
        if (str_starts_with($mimeType, 'video/')) return 'video';
        if (str_starts_with($mimeType, 'audio/')) return 'audio';
        if (str_starts_with($mimeType, 'application/')) return 'document';

        return 'other';
    }

    public function getIsImageAttribute(): bool
    {
        return $this->media_type === 'image';
    }

    public function getIsVideoAttribute(): bool
    {
        return $this->media_type === 'video';
    }

    public function getIsAudioAttribute(): bool
    {
        return $this->media_type === 'audio';
    }

    public function getFileExtensionAttribute(): string
    {
        return pathinfo($this->filename, PATHINFO_EXTENSION);
    }

    // Helper Methods
    public function getUrl(): string
    {
        return Storage::disk(config('filesystems.default'))->url($this->storage_path);
    }

    public function getDownloadUrl(): string
    {
        return route('api.media.download', $this);
    }

    public function getThumbnailUrl(): ?string
    {
        if (!$this->is_image) {
            return null;
        }

        $pathInfo = pathinfo($this->storage_path);
        $thumbnailPath = $pathInfo['dirname'] . '/thumbs/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];

        return Storage::disk(config('filesystems.default'))->url($thumbnailPath);
    }

    public function exists(): bool
    {
        return Storage::disk(config('filesystems.default'))->exists($this->storage_path);
    }

    public function delete(): ?bool
    {
        // Delete file from storage
        if ($this->exists()) {
            Storage::disk(config('filesystems.default'))->delete($this->storage_path);
        }

        // Delete thumbnail if it exists
        if ($this->is_image) {
            $this->deleteThumbnail();
        }

        return parent::delete();
    }

    public function deleteThumbnail(): void
    {
        if (!$this->is_image) return;

        $pathInfo = pathinfo($this->storage_path);
        $thumbnailPath = $pathInfo['dirname'] . '/thumbs/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];

        if (Storage::disk(config('filesystems.default'))->exists($thumbnailPath)) {
            Storage::disk(config('filesystems.default'))->delete($thumbnailPath);
        }
    }

    public function generateThumbnail(): bool
    {
        if (!$this->is_image || !$this->exists()) {
            return false;
        }

        // This would implement thumbnail generation logic
        // For now, return true as placeholder
        return true;
    }

    public function getUsageCount(): int
    {
        return $this->itemDrafts()->count() + $this->itemProds()->count();
    }

    public function isUsed(): bool
    {
        return $this->getUsageCount() > 0;
    }

    public function getUsageDetails(): array
    {
        return [
            'item_drafts' => $this->itemDrafts()->select('id', 'stem_ar')->get(),
            'item_prods' => $this->itemProds()->select('id', 'stem_ar')->get(),
        ];
    }

    public function getDimensions(): ?array
    {
        if (!$this->is_image || !isset($this->meta['dimensions'])) {
            return null;
        }

        return [
            'width' => $this->meta['dimensions']['width'] ?? null,
            'height' => $this->meta['dimensions']['height'] ?? null,
        ];
    }

    public function getDuration(): ?int
    {
        if ((!$this->is_video && !$this->is_audio) || !isset($this->meta['duration'])) {
            return null;
        }

        return $this->meta['duration'];
    }

    // Static Methods
    public static function getTotalStorageUsed(string $userId = null): int
    {
        $query = static::query();

        if ($userId) {
            $query->where('created_by', $userId);
        }

        return $query->sum('size_bytes');
    }

    public static function getStorageStats(string $userId = null): array
    {
        $query = static::query();

        if ($userId) {
            $query->where('created_by', $userId);
        }

        return [
            'total_files' => $query->count(),
            'total_size_bytes' => $query->sum('size_bytes'),
            'by_type' => $query->selectRaw('
                    CASE
                        WHEN mime_type LIKE "image/%" THEN "images"
                        WHEN mime_type LIKE "video/%" THEN "videos"
                        WHEN mime_type LIKE "audio/%" THEN "audio"
                        ELSE "documents"
                    END as media_type,
                    COUNT(*) as count,
                    SUM(size_bytes) as total_size
                ')
                ->groupBy('media_type')
                ->get()
                ->keyBy('media_type'),
        ];
    }

    public static function findOrphaned(): Collection
    {
        return static::orphaned()
            ->where('created_at', '<', now()->subDays(7))
            ->get();
    }

    public static function cleanupOrphaned(): int
    {
        $orphaned = static::findOrphaned();
        $deleted = 0;

        foreach ($orphaned as $asset) {
            if ($asset->delete()) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
