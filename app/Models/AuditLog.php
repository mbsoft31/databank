<?php
// app/Models/AuditLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    protected $appends = [
        'action_label',
        'model_name',
        'changes_count',
        'is_creation',
        'is_update',
        'is_deletion',
        'browser_info',
    ];

    public const ACTIONS = [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'viewed' => 'Viewed',
        'exported' => 'Exported',
        'published' => 'Published',
        'reviewed' => 'Reviewed',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeByUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeByModel(Builder $query, string $modelType): Builder
    {
        return $query->where('auditable_type', $modelType);
    }

    public function scopeForModel(Builder $query, string $modelType, string $modelId): Builder
    {
        return $query->where('auditable_type', $modelType)
            ->where('auditable_id', $modelId);
    }

    public function scopeCreations(Builder $query): Builder
    {
        return $query->where('action', 'created');
    }

    public function scopeUpdates(Builder $query): Builder
    {
        return $query->where('action', 'updated');
    }

    public function scopeDeletions(Builder $query): Builder
    {
        return $query->where('action', 'deleted');
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeByDateRange(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeWithChanges(Builder $query): Builder
    {
        return $query->where(function($q) {
            $q->whereNotNull('old_values')
                ->orWhereNotNull('new_values');
        });
    }

    // Accessors
    public function getActionLabelAttribute(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst($this->action);
    }

    public function getModelNameAttribute(): string
    {
        if (!$this->auditable_type) {
            return 'Unknown';
        }

        return class_basename($this->auditable_type);
    }

    public function getChangesCountAttribute(): int
    {
        $oldCount = count($this->old_values ?? []);
        $newCount = count($this->new_values ?? []);
        return max($oldCount, $newCount);
    }

    public function getIsCreationAttribute(): bool
    {
        return $this->action === 'created';
    }

    public function getIsUpdateAttribute(): bool
    {
        return $this->action === 'updated';
    }

    public function getIsDeletionAttribute(): bool
    {
        return $this->action === 'deleted';
    }

    public function getBrowserInfoAttribute(): array
    {
        if (!$this->user_agent) {
            return ['browser' => 'Unknown', 'platform' => 'Unknown'];
        }

        // Simple user agent parsing
        $userAgent = $this->user_agent;
        $browser = 'Unknown';
        $platform = 'Unknown';

        // Detect browser
        if (str_contains($userAgent, 'Chrome')) $browser = 'Chrome';
        elseif (str_contains($userAgent, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($userAgent, 'Safari')) $browser = 'Safari';
        elseif (str_contains($userAgent, 'Edge')) $browser = 'Edge';
        elseif (str_contains($userAgent, 'Opera')) $browser = 'Opera';

        // Detect platform
        if (str_contains($userAgent, 'Windows')) $platform = 'Windows';
        elseif (str_contains($userAgent, 'Macintosh')) $platform = 'macOS';
        elseif (str_contains($userAgent, 'Linux')) $platform = 'Linux';
        elseif (str_contains($userAgent, 'iPhone')) $platform = 'iOS';
        elseif (str_contains($userAgent, 'Android')) $platform = 'Android';

        return compact('browser', 'platform');
    }

    // Helper Methods
    public function hasChanges(): bool
    {
        return !empty($this->old_values) || !empty($this->new_values);
    }

    public function getChangedFields(): array
    {
        if (!$this->is_update || !$this->hasChanges()) {
            return [];
        }

        return array_keys($this->new_values ?? []);
    }

    public function getFieldChange(string $field): ?array
    {
        if (!$this->is_update) {
            return null;
        }

        $oldValue = $this->old_values[$field] ?? null;
        $newValue = $this->new_values[$field] ?? null;

        if ($oldValue === $newValue) {
            return null;
        }

        return [
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'change_type' => $this->getChangeType($oldValue, $newValue),
        ];
    }

    protected function getChangeType($oldValue, $newValue): string
    {
        if ($oldValue === null) return 'added';
        if ($newValue === null) return 'removed';
        return 'modified';
    }

    public function getAllChanges(): array
    {
        if (!$this->is_update || !$this->hasChanges()) {
            return [];
        }

        $changes = [];
        $allFields = array_unique(array_merge(
            array_keys($this->old_values ?? []),
            array_keys($this->new_values ?? [])
        ));

        foreach ($allFields as $field) {
            $change = $this->getFieldChange($field);
            if ($change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    public function getDescription(): string
    {
        $userName = $this->user->name ?? 'Unknown User';
        $modelName = $this->model_name;
        $action = strtolower($this->action_label);

        $description = sprintf("%s %s %s", $userName, $action, $modelName);

        if ($this->is_update && $this->hasChanges()) {
            $fieldCount = count($this->getChangedFields());
            $description .= " ($fieldCount field" . ($fieldCount !== 1 ? 's' : '') . " changed)";
        }

        return $description;
    }

    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'user_name' => $this->user->name ?? 'System',
            'action' => $this->action_label,
            'model' => $this->model_name,
            'timestamp' => $this->created_at->toISOString(),
            'description' => $this->getDescription(),
            'has_changes' => $this->hasChanges(),
            'changes_count' => $this->changes_count,
            'ip_address' => $this->ip_address,
            'browser_info' => $this->browser_info,
        ];
    }

    public function toTimelineEntry(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->created_at->toISOString(),
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user->name ?? 'Unknown',
            ],
            'action' => [
                'type' => $this->action,
                'label' => $this->action_label,
                'icon' => $this->getActionIcon(),
                'color' => $this->getActionColor(),
            ],
            'target' => [
                'type' => $this->model_name,
                'id' => $this->auditable_id,
            ],
            'description' => $this->getDescription(),
            'details' => $this->is_update ? $this->getAllChanges() : null,
            'metadata' => [
                'ip_address' => $this->ip_address,
                'browser' => $this->browser_info['browser'],
                'platform' => $this->browser_info['platform'],
            ],
        ];
    }

    protected function getActionIcon(): string
    {
        return match($this->action) {
            'created' => 'plus-circle',
            'updated' => 'edit',
            'deleted' => 'trash',
            'viewed' => 'eye',
            'exported' => 'download',
            'published' => 'globe',
            'reviewed' => 'check-circle',
            'approved' => 'thumbs-up',
            'rejected' => 'thumbs-down',
            default => 'activity',
        };
    }

    protected function getActionColor(): string
    {
        return match($this->action) {
            'created', 'approved' => 'green',
            'updated' => 'blue',
            'deleted', 'rejected' => 'red',
            'exported' => 'purple',
            'published' => 'indigo',
            'reviewed' => 'yellow',
            'viewed' => 'gray',
            default => 'gray',
        };
    }

    // Static Methods
    public static function logAction(
        string $action,
        Model $model,
        array $oldValues = [],
        array $newValues = [],
        ?string $userId = null
    ): self {
        return static::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public static function getActivityFeed(int $limit = 50, array $filters = []): Collection
    {
        $query = static::with('user')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['model_type'])) {
            $query->where('auditable_type', $filters['model_type']);
        }

        if (!empty($filters['actions'])) {
            $query->whereIn('action', $filters['actions']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->get();
    }

    public static function getModelHistory(Model $model): Collection
    {
        return static::forModel(get_class($model), $model->getKey())
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public static function getUserActivity(string $userId, int $days = 30): array
    {
        $logs = static::byUser($userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->get();

        return [
            'total_actions' => $logs->count(),
            'actions_by_type' => $logs->groupBy('action')->map->count(),
            'models_affected' => $logs->groupBy('auditable_type')->map->count(),
            'daily_activity' => $logs->groupBy(function($log) {
                return $log->created_at->format('Y-m-d');
            })->map->count(),
            'most_active_day' => $logs->groupBy(function($log) {
                return $log->created_at->format('Y-m-d');
            })->sortByDesc->count()->keys()->first(),
        ];
    }

    public static function getSystemActivity(int $days = 30): array
    {
        $logs = static::where('created_at', '>=', now()->subDays($days))->get();

        return [
            'total_actions' => $logs->count(),
            'unique_users' => $logs->unique('user_id')->count(),
            'actions_by_type' => $logs->groupBy('action')->map->count(),
            'models_by_activity' => $logs->groupBy('auditable_type')->map->count(),
            'hourly_distribution' => $logs->groupBy(function($log) {
                return $log->created_at->format('H');
            })->map->count(),
            'most_active_users' => $logs->groupBy('user_id')
                ->map->count()
                ->sortDesc()
                ->take(10),
        ];
    }

    public static function cleanup(int $days = 90): int
    {
        return static::where('created_at', '<', now()->subDays($days))->delete();
    }
}
