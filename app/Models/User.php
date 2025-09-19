<?php
// app/Models/User.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_admin',
        'locale',
        'preferences',
        'last_active_at',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'preferences' => 'array',
        ];
    }

    protected $appends = [
        'is_online',
        'role_label',
        'full_name',
        'activity_status',
    ];

    public const ROLES = [
        'admin' => 'مدير النظام',
        'author' => 'مؤلف المحتوى',
        'reviewer' => 'مراجع المحتوى',
        'editor' => 'محرر',
        'viewer' => 'مستعرض',
    ];

    // Relationships
    public function createdItems()
    {
        return $this->hasMany(ItemDraft::class, 'created_by');
    }

    public function updatedItems()
    {
        return $this->hasMany(ItemDraft::class, 'updated_by');
    }

    public function publishedItems()
    {
        return $this->hasMany(ItemProd::class, 'published_by');
    }

    public function reviews()
    {
        return $this->hasMany(ItemReview::class, 'reviewer_id');
    }

    public function exports()
    {
        return $this->hasMany(Export::class, 'requested_by');
    }

    public function mediaAssets()
    {
        return $this->hasMany(MediaAsset::class, 'created_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    public function personalAccessTokens()
    {
        return $this->morphMany(\Laravel\Sanctum\PersonalAccessToken::class, 'tokenable');
    }

    // Scopes
    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('is_admin', true);
    }

    public function scopeByRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    public function scopeAuthors(Builder $query): Builder
    {
        return $query->where('role', 'author');
    }

    public function scopeReviewers(Builder $query): Builder
    {
        return $query->where('role', 'reviewer');
    }

    public function scopeActive(Builder $query, int $minutes = 15): Builder
    {
        return $query->where('last_active_at', '>=', now()->subMinutes($minutes));
    }

    public function scopeInactive(Builder $query, int $days = 30): Builder
    {
        return $query->where('last_active_at', '<', now()->subDays($days))
            ->orWhereNull('last_active_at');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function scopeUnverified(Builder $query): Builder
    {
        return $query->whereNull('email_verified_at');
    }

    // Accessors
    public function getIsOnlineAttribute(): bool
    {
        return $this->last_active_at && $this->last_active_at->diffInMinutes(now()) <= 15;
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? 'مستخدم';
    }

    public function getFullNameAttribute(): string
    {
        return $this->name; // In case you want to add first_name/last_name later
    }

    public function getActivityStatusAttribute(): string
    {
        if (!$this->last_active_at) {
            return 'غير نشط';
        }

        $diffInMinutes = $this->last_active_at->diffInMinutes(now());

        return match (true) {
            $diffInMinutes <= 5 => 'متصل الآن',
            $diffInMinutes <= 15 => 'نشط مؤخراً',
            $diffInMinutes <= 60 => 'نشط خلال الساعة',
            $diffInMinutes <= 1440 => 'نشط اليوم', // 24 hours
            default => 'غير نشط',
        };
    }

    // Helper Methods
    public function isAdmin(): bool
    {
        return $this->is_admin || $this->role === 'admin';
    }

    public function isAuthor(): bool
    {
        return $this->role === 'author';
    }

    public function isReviewer(): bool
    {
        return $this->role === 'reviewer';
    }

    public function isEditor(): bool
    {
        return $this->role === 'editor';
    }

    public function canCreateItems(): bool
    {
        return in_array($this->role, ['admin', 'author', 'editor']);
    }

    public function canReviewItems(): bool
    {
        return in_array($this->role, ['admin', 'reviewer', 'editor']);
    }

    public function canPublishItems(): bool
    {
        return in_array($this->role, ['admin', 'editor']);
    }

    public function canManageUsers(): bool
    {
        return $this->isAdmin();
    }

    public function canAccessAnalytics(): bool
    {
        return in_array($this->role, ['admin', 'editor']);
    }

    public function updateLastActiveAt(): bool
    {
        return $this->update(['last_active_at' => now()]);
    }

    public function getPreference(string $key, $default = null)
    {
        return data_get($this->preferences, $key, $default);
    }

    public function setPreference(string $key, $value): bool
    {
        $preferences = $this->preferences ?? [];
        data_set($preferences, $key, $value);

        return $this->update(['preferences' => $preferences]);
    }

    public function getActivitySummary(int $days = 30): array
    {
        $fromDate = now()->subDays($days);

        return [
            'items_created' => $this->createdItems()->where('created_at', '>=', $fromDate)->count(),
            'items_updated' => $this->updatedItems()->where('updated_at', '>=', $fromDate)->count(),
            'reviews_completed' => $this->reviews()->where('created_at', '>=', $fromDate)->count(),
            'exports_requested' => $this->exports()->where('created_at', '>=', $fromDate)->count(),
            'media_uploaded' => $this->mediaAssets()->where('created_at', '>=', $fromDate)->count(),
            'total_actions' => $this->auditLogs()->where('created_at', '>=', $fromDate)->count(),
            'last_active' => $this->last_active_at?->toISOString(),
            'activity_status' => $this->activity_status,
        ];
    }

    public function getProductivityStats(): array
    {
        return [
            'total_items_created' => $this->createdItems()->count(),
            'published_items' => $this->publishedItems()->count(),
            'total_reviews' => $this->reviews()->count(),
            'approved_reviews' => $this->reviews()->where('status', 'approved')->count(),
            'avg_review_score' => $this->reviews()->whereNotNull('overall_score')->avg('overall_score'),
            'total_exports' => $this->exports()->count(),
            'successful_exports' => $this->exports()->where('status', \App\Enums\ExportStatus::Completed)->count(),
            'storage_used_mb' => round($this->mediaAssets()->sum('size_bytes') / 1048576, 2),
        ];
    }

    // Static Methods
    public static function getRoleOptions(): array
    {
        return self::ROLES;
    }

    public static function getActiveUsersCount(int $minutes = 15): int
    {
        return static::active($minutes)->count();
    }

    public static function getUsersByRole(): array
    {
        return static::selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role')
            ->toArray();
    }

    public static function getRegistrationStats(int $days = 30): array
    {
        $fromDate = now()->subDays($days);

        return [
            'new_users' => static::where('created_at', '>=', $fromDate)->count(),
            'verified_users' => static::verified()->where('created_at', '>=', $fromDate)->count(),
            'active_users' => static::where('last_active_at', '>=', $fromDate)->count(),
            'by_role' => static::where('created_at', '>=', $fromDate)
                ->selectRaw('role, COUNT(*) as count')
                ->groupBy('role')
                ->pluck('count', 'role'),
        ];
    }

    // Override the default key type for UUID
    public function getKeyType()
    {
        return 'string';
    }

    public function getIncrementing()
    {
        return false;
    }
}
