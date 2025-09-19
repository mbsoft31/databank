<?php
// app/Http/Requests/StoreConceptRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-concepts');
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|unique:concepts,code',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'description_ar' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'grade' => 'required|string|max:10',
            'strand' => 'required|string|max:50',
            'meta' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'رمز المفهوم مطلوب',
            'code.unique' => 'رمز المفهوم مستخدم مسبقاً',
            'name_ar.required' => 'اسم المفهوم باللغة العربية مطلوب',
            'grade.required' => 'الصف الدراسي مطلوب',
            'strand.required' => 'المجال مطلوب',
        ];
    }
}
