<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Identity extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'national_id',
        'full_name',
        'phone',
        'backup_phone',
        'marital_status',
        'family_members_count',
        'spouse_name',
        'spouse_phone',
        'spouse_national_id',
        'primary_address',
        'previous_address',
        'region',
        'locality',
        'branch',
        'mosque',
        'housing_type',
        'job_title',
        'health_status',
        'notes',
        'needs_review',
        'entered_at',
        'last_verified_at',
    ];

    protected $casts = [
        'needs_review' => 'bool',
        'family_members_count' => 'integer',
        'entered_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function ($identity) {
            // تنظيف رقم الهوية من المسافات والشرطات فقط (الأحرف تم رفضها في Validation)
            if (isset($identity->national_id)) {
                $identity->national_id = preg_replace('/[\s\-]/', '', $identity->national_id);
            }

            if (isset($identity->spouse_national_id)) {
                $identity->spouse_national_id = preg_replace('/[\s\-]/', '', $identity->spouse_national_id);
            }

            // تنظيف الأسماء
            if (isset($identity->full_name)) {
                $identity->full_name = trim(preg_replace('/\s+/', ' ', $identity->full_name));
            }

            if (isset($identity->spouse_name)) {
                $identity->spouse_name = trim(preg_replace('/\s+/', ' ', $identity->spouse_name));
            }

            // تنظيف أرقام الجوال
            if (isset($identity->phone)) {
                $identity->phone = trim($identity->phone);
            }

            if (isset($identity->spouse_phone)) {
                $identity->spouse_phone = trim($identity->spouse_phone);
            }

            if (isset($identity->backup_phone)) {
                $identity->backup_phone = trim($identity->backup_phone);
            }

            // التحقق من عدد أفراد الأسرة
            if (isset($identity->family_members_count)) {
                $identity->family_members_count = max(0, min(30, (int) $identity->family_members_count));
            }

            // تنظيف حقول الموقع
            if (isset($identity->region)) {
                $identity->region = trim($identity->region);
            }
            if (isset($identity->locality)) {
                $identity->locality = trim($identity->locality);
            }
            if (isset($identity->branch)) {
                $identity->branch = trim($identity->branch);
            }
            if (isset($identity->mosque)) {
                $identity->mosque = trim($identity->mosque);
            }

            // تقليم النصوص الطويلة
            if (isset($identity->notes)) {
                $identity->notes = mb_substr($identity->notes, 0, 500, 'UTF-8');
            }
        });

        static::saved(function ($identity) {
            // تحديث عدد أفراد الأسرة تلقائياً
            $actualCount = $identity->familyMembers()->count();
            if ($identity->family_members_count !== $actualCount) {
                $identity->family_members_count = $actualCount;
                $identity->saveQuietly(); // تجنب إعادة تشغيل events
            }
        });
    }

    /**
     * Accessor for national_id - إرجاع القيمة كما هي (نظيفة من Validation)
     */
    public function getNationalIdAttribute($value): ?string
    {
        return $value;
    }

    /**
     * Mutator for national_id - تنظيف من المسافات والشرطات فقط (الأحرف تم رفضها في Validation)
     */
    public function setNationalIdAttribute($value): void
    {
        $this->attributes['national_id'] = $value ? preg_replace('/[\s\-]/', '', $value) : null;
    }

    /**
     * Mutator for full_name - تنظيف عند الكتابة
     */
    public function setFullNameAttribute($value): void
    {
        $this->attributes['full_name'] = $value ? trim(preg_replace('/\s+/', ' ', $value)) : null;
    }

    /**
     * Mutator for phone - تنظيف عند الكتابة
     */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = $value ? trim($value) : null;
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }
}
