<?php
// app/Models/Concept.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

class Concept extends Model
{
    use HasFactory, HasUuids, Searchable;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'grade',
        'strand',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected $hidden = [];

    protected $appends = ['display_name', 'full_code'];

    // Scout Search Configuration
    public function searchableAs(): string
    {
        return 'concepts';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'grade' => $this->grade,
            'strand' => $this->strand,
            'keywords' => $this->extractKeywords(),
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return !empty($this->name_ar);
    }

    // Relationships
    public function itemDrafts(): BelongsToMany
    {
        return $this->belongsToMany(ItemDraft::class, 'item_draft_concepts');
    }

    public function itemProds(): BelongsToMany
    {
        return $this->belongsToMany(ItemProd::class, 'item_prod_concepts');
    }

    // Scopes
    public function scopeByGrade(Builder $query, string $grade): Builder
    {
        return $query->where('grade', $grade);
    }

    public function scopeByStrand(Builder $query, string $strand): Builder
    {
        return $query->where('strand', $strand);
    }

    public function scopeWithItemCounts(Builder $query): Builder
    {
        return $query->withCount(['itemDrafts', 'itemProds']);
    }

    public function scopePopular(Builder $query, int $limit = 10): Builder
    {
        return $query->withCount('itemProds')
            ->orderBy('item_prods_count', 'desc')
            ->limit($limit);
    }

    public function scopeUnderutilized(Builder $query, int $maxItems = 2): Builder
    {
        return $query->withCount('itemProds')
            ->having('item_prods_count', '<=', $maxItems)
            ->orderBy('item_prods_count');
    }

    // Accessors & Mutators
    public function getDisplayNameAttribute(): string
    {
        return $this->name_ar . ($this->name_en ? " ($this->name_en)" : '');
    }

    public function getFullCodeAttribute(): string
    {
        $parts = array_filter([$this->grade, $this->strand, $this->code]);
        return implode('.', $parts);
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    // Helper Methods
    public function getHierarchy(): array
    {
        return [
            'grade' => $this->grade,
            'strand' => $this->strand,
            'concept' => $this->code,
        ];
    }

    public function isInGradeRange(string $minGrade, string $maxGrade): bool
    {
        if (!$this->grade) return false;

        $currentGrade = (int) $this->grade;
        $min = (int) $minGrade;
        $max = (int) $maxGrade;

        return $currentGrade >= $min && $currentGrade <= $max;
    }

    public function getRelatedConcepts(int $limit = 5): Collection
    {
        return static::where('strand', $this->strand)
            ->where('grade', $this->grade)
            ->where('id', '!=', $this->id)
            ->withCount('itemProds')
            ->orderBy('item_prods_count', 'desc')
            ->limit($limit)
            ->get();
    }

    public function generateSuggestions(): array
    {
        return [
            'similar_concepts' => $this->getRelatedConcepts(),
            'prerequisite_concepts' => $this->getPrerequisites(),
            'next_concepts' => $this->getNextConcepts(),
        ];
    }

    protected function getPrerequisites(): Collection
    {
        // Logic to find prerequisite concepts (simpler grade/strand)
        $prevGrade = (int) $this->grade - 1;

        return static::where('strand', $this->strand)
            ->where('grade', (string) $prevGrade)
            ->withCount('itemProds')
            ->orderBy('item_prods_count', 'desc')
            ->limit(3)
            ->get();
    }

    protected function getNextConcepts(): Collection
    {
        // Logic to find next-level concepts
        $nextGrade = (int) $this->grade + 1;

        return static::where('strand', $this->strand)
            ->where('grade', (string) $nextGrade)
            ->withCount('itemProds')
            ->orderBy('item_prods_count', 'desc')
            ->limit(3)
            ->get();
    }

    protected function extractKeywords(): array
    {
        $keywords = [];

        // Extract from Arabic name
        if ($this->name_ar) {
            $keywords = array_merge($keywords, explode(' ', $this->name_ar));
        }

        // Extract from English name
        if ($this->name_en) {
            $keywords = array_merge($keywords, explode(' ', $this->name_en));
        }

        // Add code and hierarchy
        $keywords[] = $this->code;
        $keywords[] = $this->grade;
        $keywords[] = $this->strand;

        return array_filter($keywords);
    }

    // Static Methods
    public static function findByCode(string $code): ?self
    {
        return static::where('code', strtoupper($code))->first();
    }

    public static function getGradeOptions(): array
    {
        return static::distinct('grade')
            ->whereNotNull('grade')
            ->orderBy('grade')
            ->pluck('grade')
            ->toArray();
    }

    public static function getStrandOptions(): array
    {
        return static::distinct('strand')
            ->whereNotNull('strand')
            ->orderBy('strand')
            ->pluck('strand')
            ->toArray();
    }

    public static function getCurriculumStructure(): array
    {
        return static::selectRaw('grade, strand, COUNT(*) as concept_count')
            ->whereNotNull('grade')
            ->whereNotNull('strand')
            ->groupBy(['grade', 'strand'])
            ->orderBy('grade')
            ->orderBy('strand')
            ->get()
            ->groupBy('grade')
            ->map(function ($grades) {
                return $grades->keyBy('strand');
            })
            ->toArray();
    }
}
