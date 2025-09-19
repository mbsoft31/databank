<?php
// app/Http/Requests/UpdateProfileRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($this->user())],
            'locale' => 'sometimes|string|in:ar,en',
            'preferences' => 'sometimes|array',
            'preferences.theme' => 'nullable|string|in:light,dark,auto',
            'preferences.language' => 'nullable|string|in:ar,en',
            'preferences.notifications' => 'nullable|array',
            'preferences.ui' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'الاسم مطلوب',
            'email.required' => 'البريد الإلكتروني مطلوب',
            'email.unique' => 'البريد الإلكتروني مسجل لمستخدم آخر',
        ];
    }
}
