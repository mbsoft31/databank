<?php
// app/Models/Tag.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Scout\Searchable;

class Tag extends Model
{
    use HasFactory, HasUuids, Searchable;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'color',
    ];

    protected $appends = ['display_name', 'usage_count'];

    // Scout Search Configuration
    public function searchableAs(): string
    {
        return 'tags';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
        ];
    }

    // Relationships
    public function itemDrafts(): BelongsToMany
    {
        return $this->belongsToMany(ItemDraft::class, 'item_draft_tags');
    }

    public function itemProds(): BelongsToMany
    {
        return $this->belongsToMany(ItemProd::class, 'item_prod_tags');
    }

    // Scopes
    public function scopePopular(Builder $query, int $limit = 10): Builder
    {
        return $query->withCount(['itemDrafts', 'itemProds'])
            ->orderByRaw('(item_drafts_count + item_prods_count) DESC')
            ->limit($limit);
    }

    public function scopeWithUsageCounts(Builder $query): Builder
    {
        return $query->withCount(['itemDrafts', 'itemProds']);
    }

    public function scopeByColor(Builder $query, string $color): Builder
    {
        return $query->where('color', $color);
    }

    // Accessors & Mutators
    public function getDisplayNameAttribute(): string
    {
        return $this->name_ar . ($this->name_en ? " ({$this->name_en})" : '');
    }

    public function getUsageCountAttribute(): int
    {
        return ($this->item_drafts_count ?? 0) + ($this->item_prods_count ?? 0);
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtolower(trim($value));
    }

    public function setColorAttribute(string $value): void
    {
        // Ensure color is a valid hex color
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $value)) {
            $value = '#6366f1'; // Default color
        }
        $this->attributes['color'] = $value;
    }

    // Helper Methods
    public function getTotalUsageCount(): int
    {
        return $this->itemDrafts()->count() + $this->itemProds()->count();
    }

    public function isPopular(): bool
    {
        return $this->getTotalUsageCount() >= 10;
    }

    public function getStyleAttribute(): array
    {
        return [
            'backgroundColor' => $this->color,
            'color' => $this->getContrastColor(),
        ];
    }

    protected function getContrastColor(): string
    {
        // Calculate if we need light or dark text on this background
        $hex = ltrim($this->color, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Calculate luminance
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.5 ? '#000000' : '#ffffff';
    }

    // Static Methods
    public static function findByCode(string $code): ?self
    {
        return static::where('code', strtolower($code))->first();
    }

    public static function getPopularColors(): array
    {
        return static::selectRaw('color, COUNT(*) as usage_count')
            ->groupBy('color')
            ->orderBy('usage_count', 'desc')
            ->limit(10)
            ->pluck('usage_count', 'color')
            ->toArray();
    }

    public static function generateColorPalette(): array
    {
        return [
            '#ef4444', // red
            '#f97316', // orange
            '#eab308', // yellow
            '#22c55e', // green
            '#06b6d4', // cyan
            '#3b82f6', // blue
            '#6366f1', // indigo
            '#8b5cf6', // violet
            '#ec4899', // pink
            '#64748b', // slate
        ];
    }
}
