<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminLoginRequest extends FormRequest
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
        // تنظيف كلمة المرور من المسافات
        if ($this->has('password')) {
            $this->merge([
                'password' => trim($this->password),
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
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'password.required' => 'يرجى إدخال كلمة المرور.',
            'password.min' => 'كلمة المرور يجب أن تكون على الأقل 8 أحرف.',
            'password.max' => 'كلمة المرور طويلة جداً.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $password = $this->input('password');

            // منع محاولات SQL Injection في كلمة المرور
            if (preg_match('/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b)/i', $password)) {
                $validator->errors()->add(
                    'password',
                    'كلمة المرور تحتوي على أحرف غير مسموحة.'
                );
            }

            // منع محاولات XSS
            if (preg_match('/[<>"\']/', $password)) {
                $validator->errors()->add(
                    'password',
                    'كلمة المرور تحتوي على أحرف غير مسموحة.'
                );
            }
        });
    }
}
