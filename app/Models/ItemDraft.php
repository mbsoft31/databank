<?php
// app/Models/ItemDraft.php
namespace App\Models;

use App\Enums\ItemStatus;
use App\Traits\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class ItemDraft extends Model
{
    use HasFactory, HasUuids, Searchable, HasAuditTrail;

    protected $fillable = [
        'stem_ar', 'latex', 'item_type', 'difficulty', 'meta',
        'status', 'created_by', 'updated_by'
    ];

    protected $casts = [
        'difficulty' => 'float',
        'meta' => 'array',
        'status' => ItemStatus::class,
    ];

    public function searchableAs(): string
    {
        return 'item_drafts';
    }

    public function toSearchableArray(): array
    {
        $this->loadMissing(['tags:id,code', 'concepts:id,code']);
        return [
            'id' => $this->id,
            'stem_ar' => $this->stem_ar,
            'latex' => $this->latex,
            'item_type' => $this->item_type,
            'tags' => $this->tags->pluck('code'),
            'concepts' => $this->concepts->pluck('code'),
            'status' => $this->status->value,
            'created_at' => optional($this->created_at)->timestamp,
        ];
    }

    // Relationships
    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'item_draft_concepts');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'item_draft_tags');
    }

    public function options(): MorphMany
    {
        return $this->morphMany(ItemOption::class, 'itemable')->orderBy('order_index');
    }

    public function hints(): MorphMany
    {
        return $this->morphMany(ItemHint::class, 'itemable')->orderBy('order_index');
    }

    public function solutions(): MorphMany
    {
        return $this->morphMany(ItemSolution::class, 'itemable');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ItemReview::class);
    }

    public function contentHash(): HasOne
    {
        return $this->hasOne(ContentHash::class);
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('item_type', $type);
    }

    public function scopeByAuthor($query, $authorId)
    {
        return $query->where('created_by', $authorId);
    }

    // Methods
    public function canBeReviewed(): bool
    {
        return $this->status === ItemStatus::Draft;
    }

    public function canBePublished(): bool
    {
        return $this->status === ItemStatus::Approved;
    }

    public function publish(): ItemProd
    {
        $prod = ItemProd::create([
            'source_draft_id' => $this->id,
            'stem_ar' => $this->stem_ar,
            'latex' => $this->latex,
            'item_type' => $this->item_type,
            'difficulty' => $this->difficulty,
            'meta' => $this->meta,
            'published_at' => now(),
            'published_by' => auth()->id(),
        ]);

        // Copy relationships
        $prod->concepts()->attach($this->concepts->pluck('id'));
        $prod->tags()->attach($this->tags->pluck('id'));

        foreach ($this->options as $option) {
            $prod->options()->create($option->only(['text_ar', 'latex', 'is_correct', 'order_index']));
        }

        foreach ($this->hints as $hint) {
            $prod->hints()->create($hint->only(['text_ar', 'latex', 'order_index']));
        }

        foreach ($this->solutions as $solution) {
            $prod->solutions()->create($solution->only(['text_ar', 'latex', 'solution_type']));
        }

        $this->update(['status' => ItemStatus::Published]);
        return $prod;
    }
}
