<?php

namespace App\Http\Requests;

use App\Rules\NationalIdForSearch;
use Illuminate\Foundation\Http\FormRequest;

class PublicIdentitySearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // تنظيف البيانات من المسافات والشرطات فقط (لا نزيل الأحرف هنا - الـ Validation سيرفضها)
        if ($this->has('national_id')) {
            $this->merge([
                'national_id' => preg_replace('/[\s\-]/', '', $this->national_id),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'national_id' => ['required', new NationalIdForSearch()],
        ];
    }

    public function messages(): array
    {
        return [
            'national_id.required' => 'يرجى إدخال رقم الهوية.',
        ];
    }
}
