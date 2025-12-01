<?php

namespace App\Http\Requests;

use App\Rules\ArabicName;
use App\Rules\MaxArraySize;
use App\Rules\NationalId;
use App\Rules\NationalIdForUpdate;
use App\Rules\PhoneNumber;
use App\Rules\ValidDate;
use App\Rules\ValidMaritalStatus;
use App\Rules\ValidRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminIdentityRequest extends FormRequest
{
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

        if ($this->has('phone')) {
            $this->merge([
                'phone' => trim($this->phone),
            ]);
        }

        if ($this->has('backup_phone')) {
            $this->merge([
                'backup_phone' => trim($this->backup_phone),
            ]);
        }

        if ($this->has('spouse_phone')) {
            $this->merge([
                'spouse_phone' => trim($this->spouse_phone),
            ]);
        }

        if ($this->has('full_name')) {
            $this->merge([
                'full_name' => trim(preg_replace('/\s+/', ' ', $this->full_name)),
            ]);
        }

        if ($this->has('spouse_name')) {
            $this->merge([
                'spouse_name' => trim(preg_replace('/\s+/', ' ', $this->spouse_name)),
            ]);
        }

        // تنظيف حقول الموقع
        if ($this->has('region')) {
            $this->merge(['region' => trim($this->region)]);
        }
        if ($this->has('locality')) {
            $this->merge(['locality' => trim($this->locality)]);
        }
        if ($this->has('branch')) {
            $this->merge(['branch' => trim($this->branch)]);
        }
        if ($this->has('mosque')) {
            $this->merge(['mosque' => trim($this->mosque)]);
        }

        // تنظيف أفراد الأسرة
        if ($this->has('family_members') && is_array($this->family_members)) {
            $cleaned = [];
            foreach ($this->family_members as $member) {
                if (isset($member['member_name'])) {
                    $member['member_name'] = trim(preg_replace('/\s+/', ' ', $member['member_name']));
                }
                if (isset($member['national_id'])) {
                    $member['national_id'] = preg_replace('/[\s\-]/', '', $member['national_id']);
                }
                if (isset($member['phone'])) {
                    $member['phone'] = trim($member['phone']);
                }
                $cleaned[] = $member;
            }
            $this->merge(['family_members' => $cleaned]);
        }
    }

    public function rules(): array
    {
        $identity = $this->route('identity');

        return [
            'national_id' => [
                'required',
                new NationalIdForUpdate($identity?->id),
                Rule::unique('identities', 'national_id')->ignore($identity?->id),
            ],
            'full_name' => ['required', 'string', 'max:120', new ArabicName()],
            'phone' => ['nullable', 'string', 'max:30', new PhoneNumber()],
            'backup_phone' => ['nullable', 'string', 'max:30', new PhoneNumber()],
            'marital_status' => ['nullable', 'string', 'max:40', new ValidMaritalStatus()],
            'family_members_count' => ['required', 'integer', 'min:0', 'max:30'],
            'spouse_name' => ['nullable', 'string', 'max:120', new ArabicName()],
            'spouse_phone' => ['nullable', 'string', 'max:30', new PhoneNumber()],
            'spouse_national_id' => ['nullable', 'string', 'max:20', new NationalId()],
            'primary_address' => ['nullable', 'string', 'max:190'],
            'previous_address' => ['nullable', 'string', 'max:190'],
            'region' => ['nullable', 'string', 'max:100'],
            'locality' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'mosque' => ['nullable', 'string', 'max:100'],
            'housing_type' => [
                'nullable',
                'string',
                'max:60',
                Rule::in(['داخل مركز إيواء', 'بيت أقارب', 'خيمة', 'مخيم عشوائي', 'بيت ملك', 'بيت ايجار']),
            ],
            'job_title' => [
                'nullable',
                'string',
                'max:120',
                Rule::in(['موظف', 'عامل', 'قطاع خاص', 'لا يعمل']),
            ],
            'health_status' => [
                'nullable',
                'string',
                'max:30',
                Rule::in(['سليم', 'معاف', 'مصاب', 'وأنت']),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'needs_review' => ['sometimes', 'boolean'],
            'entered_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'last_verified_at' => ['nullable', 'date', 'before_or_equal:now'],
            'family_members' => ['nullable', 'array', new MaxArraySize(30)],
            'family_members.*.id' => ['sometimes', 'integer', 'exists:family_members,id'],
            'family_members.*.member_name' => ['required_with:family_members', 'string', 'max:120', new ArabicName()],
            'family_members.*.relation' => ['required_with:family_members', 'string', 'max:60', new ValidRelation()],
            'family_members.*.national_id' => ['nullable', 'string', 'max:20', new NationalId()],
            'family_members.*.phone' => ['nullable', 'string', 'max:30', new PhoneNumber()],
            'family_members.*.birth_date' => ['nullable', 'date', new ValidDate(120, 0)],
            'family_members.*.is_guardian' => ['sometimes', 'boolean'],
            'family_members.*.needs_care' => ['sometimes', 'boolean'],
            'family_members.*.health_status' => [
                'nullable',
                'string',
                'max:30',
                Rule::in(['سليم', 'معاف', 'مصاب', 'وأنت']),
            ],
            'family_members.*.education_status' => [
                'nullable',
                'string',
                'max:30',
                Rule::in(['روضة', 'مدرسة', 'جامعة', 'لا يدرس', 'يعمل']),
            ],
            'family_members.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'national_id.required' => 'يرجى إدخال رقم الهوية.',
            'national_id.unique' => 'رقم الهوية موجود مسبقاً في النظام.',
            'full_name.required' => 'يرجى إدخال الاسم الرباعي.',
            'full_name.max' => 'الاسم الرباعي يجب أن لا يزيد عن 120 حرفاً.',
            'phone.max' => 'رقم الجوال يجب أن لا يزيد عن 30 حرفاً.',
            'marital_status.max' => 'الحالة الاجتماعية يجب أن لا تزيد عن 40 حرفاً.',
            'family_members_count.required' => 'يرجى تحديد عدد أفراد الأسرة.',
            'family_members_count.integer' => 'عدد أفراد الأسرة يجب أن يكون رقماً.',
            'family_members_count.min' => 'عدد أفراد الأسرة يجب أن يكون 0 أو أكثر.',
            'family_members_count.max' => 'عدد أفراد الأسرة يجب أن لا يزيد عن 30.',
            'spouse_name.max' => 'اسم الزوج/الزوجة يجب أن لا يزيد عن 120 حرفاً.',
            'spouse_phone.max' => 'جوال الزوج/الزوجة يجب أن لا يزيد عن 30 حرفاً.',
            'spouse_national_id.max' => 'هوية الزوج/الزوجة يجب أن لا تزيد عن 20 حرفاً.',
            'primary_address.max' => 'عنوان السكن الحالي يجب أن لا يزيد عن 190 حرفاً.',
            'previous_address.max' => 'عنوان السكن السابق يجب أن لا يزيد عن 190 حرفاً.',
            'region.max' => 'المنطقة يجب أن لا تزيد عن 100 حرف.',
            'locality.max' => 'المحلية يجب أن لا تزيد عن 100 حرف.',
            'branch.max' => 'الشعبة يجب أن لا تزيد عن 100 حرف.',
            'mosque.max' => 'المسجد يجب أن لا يزيد عن 100 حرف.',
            'housing_type.in' => 'طبيعة السكن المحددة غير صحيحة.',
            'job_title.in' => 'المهنة المحددة غير صحيحة.',
            'health_status.in' => 'الحالة الصحية المحددة غير صحيحة.',
            'notes.max' => 'الملاحظات يجب أن لا تزيد عن 500 حرف.',
            'needs_review.boolean' => 'حقل يحتاج مراجعة يجب أن يكون نعم أو لا.',
            'entered_at.date' => 'تاريخ الإدخال غير صحيح.',
            'entered_at.before_or_equal' => 'تاريخ الإدخال لا يمكن أن يكون في المستقبل.',
            'last_verified_at.date' => 'تاريخ آخر مراجعة غير صحيح.',
            'last_verified_at.before_or_equal' => 'تاريخ آخر مراجعة لا يمكن أن يكون في المستقبل.',
            'family_members.array' => 'أفراد الأسرة يجب أن يكونوا في صيغة مصفوفة.',
            'family_members.*.id.exists' => 'عضو الأسرة المحدد غير موجود.',
            'family_members.*.member_name.required_with' => 'يرجى إدخال اسم كل فرد من أفراد الأسرة.',
            'family_members.*.member_name.max' => 'اسم الفرد يجب أن لا يزيد عن 120 حرفاً.',
            'family_members.*.relation.required_with' => 'حدد صلة القرابة لكل فرد.',
            'family_members.*.relation.max' => 'صلة القرابة يجب أن لا تزيد عن 60 حرفاً.',
            'family_members.*.national_id.max' => 'رقم هوية الفرد يجب أن لا يزيد عن 20 حرفاً.',
            'family_members.*.phone.max' => 'رقم جوال الفرد يجب أن لا يزيد عن 30 حرفاً.',
            'family_members.*.birth_date.date' => 'تاريخ الميلاد غير صحيح.',
            'family_members.*.is_guardian.boolean' => 'حقل يعتبر عائلاً يجب أن يكون نعم أو لا.',
            'family_members.*.needs_care.boolean' => 'حقل يحتاج رعاية يجب أن يكون نعم أو لا.',
            'family_members.*.health_status.in' => 'الحالة الصحية المحددة غير صحيحة.',
            'family_members.*.education_status.in' => 'الحالة الدراسية المحددة غير صحيحة.',
            'family_members.*.notes.max' => 'ملاحظات الفرد يجب أن لا تزيد عن 500 حرف.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // حماية من SQL Injection - فحص جميع المدخلات النصية
            $this->checkSqlInjection($validator);
            
            // التحقق من تطابق عدد أفراد الأسرة
            $familyMembersCount = $this->input('family_members_count', 0);
            $familyMembersArray = $this->input('family_members', []);

            if (count($familyMembersArray) !== (int) $familyMembersCount) {
                $validator->errors()->add(
                    'family_members_count',
                    'عدد أفراد الأسرة لا يطابق عدد الأفراد المدخلين.'
                );
            }

            // التحقق من عدم تكرار أرقام الهوية
            $nationalIds = array_filter([
                $this->input('national_id'),
                $this->input('spouse_national_id'),
            ]);

            if (!empty($familyMembersArray)) {
                foreach ($familyMembersArray as $member) {
                    if (!empty($member['national_id'])) {
                        $nationalIds[] = $member['national_id'];
                    }
                }
            }

            $uniqueIds = array_unique($nationalIds);
            if (count($nationalIds) !== count($uniqueIds)) {
                $validator->errors()->add(
                    'national_id',
                    'يوجد تكرار في أرقام الهوية. يجب أن تكون كل هوية فريدة.'
                );
            }

            // التحقق من بيانات الزوج/الزوجة عند الحالة الاجتماعية "متزوج" أو "متعدد الزوجات"
            $maritalStatus = $this->input('marital_status');
            $requiresSpouse = in_array($maritalStatus, ['متزوج', 'متعدد الزوجات']);

            if ($requiresSpouse) {
                if (empty($this->input('spouse_name'))) {
                    $validator->errors()->add(
                        'spouse_name',
                        'اسم الزوج/الزوجة مطلوب عند اختيار الحالة الاجتماعية "متزوج" أو "متعدد الزوجات".'
                    );
                }
            }
        });
    }

    /**
     * Check for SQL Injection patterns in input data
     */
    protected function checkSqlInjection($validator): void
    {
        $sqlPatterns = [
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|EXECUTE|UNION|TRUNCATE|REPLACE)\b)/i',
            '/(\b(OR|AND)\s+\d+\s*=\s*\d+)/i',
            '/(--|#|\/\*|\*\/)/',
            '/(\b(CHAR|ASCII|SUBSTRING|CONCAT|CAST|CONVERT|BENCHMARK|SLEEP|WAITFOR|DELAY)\s*\()/i',
            '/(UNION\s+(ALL\s+)?SELECT)/i',
            '/(\'\s*(OR|AND)\s*[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?)/i',
        ];

        $allInput = $this->all();
        foreach ($allInput as $key => $value) {
            if (is_string($value) && !empty(trim($value))) {
                foreach ($sqlPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $validator->errors()->add(
                            $key,
                            'المحتوى المدخل يحتوي على أحرف أو كلمات غير مسموحة.'
                        );
                        \Log::warning('SQL Injection attempt in AdminIdentityRequest', [
                            'field' => $key,
                            'ip' => $this->ip(),
                            'user_agent' => $this->userAgent(),
                        ]);
                        break;
                    }
                }
            } elseif (is_array($value)) {
                $this->checkArrayForSqlInjection($validator, $value, $key);
            }
        }
    }

    /**
     * Recursively check arrays for SQL injection
     */
    protected function checkArrayForSqlInjection($validator, array $array, string $parentKey = ''): void
    {
        foreach ($array as $key => $value) {
            $fullKey = $parentKey ? "{$parentKey}.{$key}" : $key;
            
            if (is_string($value) && !empty(trim($value))) {
                $sqlPatterns = [
                    '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|EXECUTE|UNION|TRUNCATE|REPLACE)\b)/i',
                    '/(\b(OR|AND)\s+\d+\s*=\s*\d+)/i',
                    '/(--|#|\/\*|\*\/)/',
                ];
                
                foreach ($sqlPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $validator->errors()->add(
                            $fullKey,
                            'المحتوى المدخل يحتوي على أحرف أو كلمات غير مسموحة.'
                        );
                        break;
                    }
                }
            } elseif (is_array($value)) {
                $this->checkArrayForSqlInjection($validator, $value, $fullKey);
            }
        }
    }
}
