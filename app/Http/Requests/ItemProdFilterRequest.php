<?php
// app/Http/Requests/ItemProdFilterRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItemProdFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:200',
            'item_type' => 'nullable|string|in:multiple_choice,short_answer,essay,numerical,matching',
            'difficulty_min' => 'nullable|numeric|min:1|max:5',
            'difficulty_max' => 'nullable|numeric|min:1|max:5|gte:difficulty_min',
            'grade' => 'nullable|string',
            'strand' => 'nullable|string',
            'concept_ids' => 'nullable|array',
            'concept_ids.*' => 'uuid|exists:concepts,id',
            'tag_codes' => 'nullable|array',
            'tag_codes.*' => 'string|exists:tags,code',
            'has_solutions' => 'nullable|boolean',
            'has_hints' => 'nullable|boolean',
            'published_after' => 'nullable|date',
            'published_before' => 'nullable|date|after_or_equal:published_after',
            'sort_by' => 'nullable|string|in:published_at,difficulty,stem_ar,quality_score',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
