<?php

namespace App\Http\Requests;

use App\Rules\SecureFileUpload;
use Illuminate\Foundation\Http\FormRequest;

class FileUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                new SecureFileUpload(),
            ],
            'fields' => ['nullable', 'string', 'max:5000'], // JSON string
            'direction' => ['nullable', 'string', 'in:rtl,ltr'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'يرجى اختيار ملف للرفع.',
            'file.file' => 'الملف المرفوع غير صحيح.',
            'fields.max' => 'حقل الحقول كبير جداً.',
            'direction.in' => 'اتجاه البيانات يجب أن يكون rtl أو ltr.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // التحقق من صيغة fields إذا كانت موجودة
            if ($this->has('fields') && !empty($this->input('fields'))) {
                $fields = json_decode($this->input('fields'), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $validator->errors()->add(
                        'fields',
                        'صيغة حقل الحقول غير صحيحة.'
                    );
                } elseif (!is_array($fields)) {
                    $validator->errors()->add(
                        'fields',
                        'حقل الحقول يجب أن يكون مصفوفة.'
                    );
                } elseif (count($fields) > 100) {
                    $validator->errors()->add(
                        'fields',
                        'عدد الحقول كبير جداً.'
                    );
                }
            }
        });
    }
}

