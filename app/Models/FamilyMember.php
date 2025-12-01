<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'identity_id',
        'member_name',
        'relation',
        'national_id',
        'phone',
        'birth_date',
        'is_guardian',
        'needs_care',
        'health_status',
        'education_status',
        'notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'is_guardian' => 'bool',
        'needs_care' => 'bool',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function ($member) {
            // تنظيف رقم الهوية من المسافات والشرطات فقط (الأحرف تم رفضها في Validation)
            if (isset($member->national_id)) {
                $member->national_id = preg_replace('/[\s\-]/', '', $member->national_id);
            }

            // تنظيف الاسم
            if (isset($member->member_name)) {
                $member->member_name = trim(preg_replace('/\s+/', ' ', $member->member_name));
            }

            // تنظيف رقم الجوال
            if (isset($member->phone)) {
                $member->phone = trim($member->phone);
            }

            // تنظيف الحالة الدراسية
            if (isset($member->education_status)) {
                $member->education_status = trim($member->education_status);
            }

            // تقليم النصوص الطويلة
            if (isset($member->notes)) {
                $member->notes = mb_substr($member->notes, 0, 500, 'UTF-8');
            }

            // التحقق من القيم المنطقية
            $member->is_guardian = (bool) ($member->is_guardian ?? false);
            $member->needs_care = (bool) ($member->needs_care ?? false);
        });
    }

    /**
     * Mutator for national_id - تنظيف من المسافات والشرطات فقط (الأحرف تم رفضها في Validation)
     */
    public function setNationalIdAttribute($value): void
    {
        $this->attributes['national_id'] = $value ? preg_replace('/[\s\-]/', '', $value) : null;
    }

    /**
     * Mutator for member_name - تنظيف عند الكتابة
     */
    public function setMemberNameAttribute($value): void
    {
        $this->attributes['member_name'] = $value ? trim(preg_replace('/\s+/', ' ', $value)) : null;
    }

    /**
     * Mutator for phone - تنظيف عند الكتابة
     */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = $value ? trim($value) : null;
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }
}
