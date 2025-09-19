<?php
// app/Models/Export.php
namespace App\Models;

use App\Enums\ExportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Export extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kind',
        'params',
        'status',
        'file_path',
        'error_message',
        'completed_at',
        'requested_by',
    ];

    protected $casts = [
        'params' => 'array',
        'status' => ExportStatus::class,
        'completed_at' => 'datetime',
    ];

    protected $appends = [
        'kind_label',
        'status_label',
        'is_completed',
        'is_failed',
        'processing_time',
        'file_size_formatted',
        'estimated_items',
    ];

    public const EXPORT_KINDS = [
        'worksheet_markdown' => 'Markdown Worksheet',
        'worksheet_html' => 'HTML Worksheet',
        'worksheet_pdf' => 'PDF Worksheet',
        'item_bank_json' => 'JSON Item Bank',
        'analytics_csv' => 'Analytics CSV',
    ];

    // Relationships
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    // Scopes
    public function scopeByKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function scopeByStatus(Builder $query, ExportStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ExportStatus::Pending);
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', ExportStatus::Processing);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ExportStatus::Completed);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ExportStatus::Failed);
    }

    public function scopeByRequester(Builder $query, string $userId): Builder
    {
        return $query->where('requested_by', $userId);
    }

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeExpired(Builder $query, int $days = 7): Builder
    {
        return $query->where('completed_at', '<', now()->subDays($days))
            ->where('status', ExportStatus::Completed);
    }

    // Accessors
    public function getKindLabelAttribute(): string
    {
        return self::EXPORT_KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', $this->kind));
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            ExportStatus::Pending => 'Pending',
            ExportStatus::Processing => 'Processing',
            ExportStatus::Completed => 'Completed',
            ExportStatus::Failed => 'Failed',
            default => 'Unknown',
        };
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === ExportStatus::Completed;
    }

    public function getIsFailedAttribute(): bool
    {
        return $this->status === ExportStatus::Failed;
    }

    public function getProcessingTimeAttribute(): ?int
    {
        if (!$this->completed_at) {
            return null;
        }

        return $this->created_at->diffInSeconds($this->completed_at);
    }

    public function getFileSizeFormattedAttribute(): ?string
    {
        if (!$this->file_path || !$this->fileExists()) {
            return null;
        }

        $bytes = Storage::disk(config('services.exports.storage_disk', 'local'))
            ->size($this->file_path);

        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getEstimatedItemsAttribute(): int
    {
        return count($this->params['item_ids'] ?? []);
    }

    // Helper Methods
    public function isPending(): bool
    {
        return $this->status === ExportStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return $this->status === ExportStatus::Processing;
    }

    public function isCompleted(): bool
    {
        return $this->status === ExportStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === ExportStatus::Failed;
    }

    public function canBeDownloaded(): bool
    {
        return $this->isCompleted() && $this->file_path && $this->fileExists();
    }

    public function canBeCancelled(): bool
    {
        return $this->isPending() || $this->isProcessing();
    }

    public function fileExists(): bool
    {
        if (!$this->file_path) {
            return false;
        }

        return Storage::disk(config('services.exports.storage_disk', 'local'))
            ->exists($this->file_path);
    }

    public function getDownloadUrl(): ?string
    {
        if (!$this->canBeDownloaded()) {
            return null;
        }

        return route('api.exports.download', $this);
    }

    public function getFileSize(): ?int
    {
        if (!$this->fileExists()) {
            return null;
        }

        return Storage::disk(config('services.exports.storage_disk', 'local'))
            ->size($this->file_path);
    }

    public function deleteFile(): bool
    {
        if (!$this->file_path) {
            return false;
        }

        $disk = Storage::disk(config('services.exports.storage_disk', 'local'));

        if ($disk->exists($this->file_path)) {
            return $disk->delete($this->file_path);
        }

        return true; // File doesn't exist, consider it deleted
    }

    public function cancel(): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => ExportStatus::Failed,
            'error_message' => 'Cancelled by user',
        ]);

        return true;
    }

    public function markAsProcessing(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        return $this->update(['status' => ExportStatus::Processing]);
    }

    public function markAsCompleted(string $filePath): bool
    {
        if (!$this->isProcessing()) {
            return false;
        }

        return $this->update([
            'status' => ExportStatus::Completed,
            'file_path' => $filePath,
            'completed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => ExportStatus::Failed,
            'error_message' => $errorMessage,
        ]);
    }

    public function getExportSummary(): array
    {
        $summary = [
            'id' => $this->id,
            'kind' => $this->kind_label,
            'status' => $this->status_label,
            'created_at' => $this->created_at->toISOString(),
            'requested_by' => $this->requester->name ?? 'Unknown',
        ];

        if ($this->isCompleted()) {
            $summary['completed_at'] = $this->completed_at->toISOString();
            $summary['processing_time_seconds'] = $this->processing_time;
            $summary['file_size'] = $this->file_size_formatted;
            $summary['download_url'] = $this->getDownloadUrl();
        }

        if ($this->isFailed()) {
            $summary['error'] = $this->error_message;
        }

        $summary['params'] = [
            'estimated_items' => $this->estimated_items,
            'title' => $this->params['title'] ?? null,
            'include_solutions' => $this->params['include_solutions'] ?? false,
            'include_hints' => $this->params['include_hints'] ?? false,
        ];

        return $summary;
    }

    public function getProgressEstimate(): array
    {
        if ($this->isCompleted() || $this->isFailed()) {
            return ['progress' => 100, 'status' => $this->status_label];
        }

        if ($this->isProcessing()) {
            // Estimate progress based on processing time
            $elapsed = $this->updated_at->diffInSeconds(now());
            $estimatedTotal = $this->estimated_items * 2; // 2 seconds per item
            $progress = min(95, ($elapsed / $estimatedTotal) * 100);

            return [
                'progress' => round($progress),
                'status' => 'Processing...',
                'elapsed_seconds' => $elapsed,
            ];
        }

        if ($this->isPending()) {
            // Estimate position in queue
            $queuePosition = static::pending()
                ->where('created_at', '<', $this->created_at)
                ->count();

            return [
                'progress' => 0,
                'status' => 'Queued',
                'queue_position' => $queuePosition + 1,
            ];
        }

        return ['progress' => 0, 'status' => 'Unknown'];
    }

    // Static Methods
    public static function getKindOptions(): array
    {
        return self::EXPORT_KINDS;
    }

    public static function getUserStats(string $userId): array
    {
        $exports = static::where('requested_by', $userId)->get();

        return [
            'total_exports' => $exports->count(),
            'completed_exports' => $exports->where('status', ExportStatus::Completed)->count(),
            'failed_exports' => $exports->where('status', ExportStatus::Failed)->count(),
            'exports_by_kind' => $exports->groupBy('kind')->map->count(),
            'total_items_exported' => $exports->where('status', ExportStatus::Completed)
                ->sum('estimated_items'),
            'avg_processing_time' => $exports->where('status', ExportStatus::Completed)
                ->whereNotNull('processing_time')
                ->avg('processing_time'),
            'recent_exports' => $exports->where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }

    public static function getSystemStats(): array
    {
        return [
            'total_exports' => static::count(),
            'exports_by_status' => static::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'exports_by_kind' => static::selectRaw('kind, COUNT(*) as count')
                ->groupBy('kind')
                ->pluck('count', 'kind'),
            'avg_processing_time' => static::completed()
                ->whereNotNull('processing_time')
                ->avg('processing_time'),
            'total_file_size' => static::completed()
                ->get()
                ->sum(function ($export) {
                    return $export->getFileSize() ?? 0;
                }),
            'success_rate' => (static::completed()->count() / max(1, static::count())) * 100,
            'queue_length' => static::pending()->count(),
        ];
    }

    public static function cleanupExpired(): int
    {
        $expiredExports = static::expired()->get();
        $cleaned = 0;

        foreach ($expiredExports as $export) {
            if ($export->deleteFile()) {
                $export->delete();
                $cleaned++;
            }
        }

        return $cleaned;
    }

    public static function getQueueStatus(): array
    {
        return [
            'pending_count' => static::pending()->count(),
            'processing_count' => static::processing()->count(),
            'oldest_pending' => static::pending()
                ->orderBy('created_at')
                ->first()
                ?->created_at?->diffForHumans(),
            'estimated_wait_time' => static::pending()->count() * 30, // 30 seconds per export
        ];
    }
}
