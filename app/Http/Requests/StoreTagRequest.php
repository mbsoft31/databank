<?php
// app/Http/Requests/StoreTagRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-tags');
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|unique:tags,code',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'color' => 'required|string|regex:/^#[a-fA-F0-9]{6}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'رمز التصنيف مطلوب',
            'code.unique' => 'رمز التصنيف مستخدم مسبقاً',
            'name_ar.required' => 'اسم التصنيف باللغة العربية مطلوب',
            'color.required' => 'لون التصنيف مطلوب',
            'color.regex' => 'تنسيق اللون غير صحيح (استخدم #RRGGBB)',
        ];
    }
}
