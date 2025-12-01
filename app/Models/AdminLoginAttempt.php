<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AdminLoginAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip_address',
        'user_agent',
        'success',
        'attempted_at',
    ];

    protected $casts = [
        'success' => 'boolean',
        'attempted_at' => 'datetime',
    ];

    /**
     * تسجيل محاولة دخول
     */
    public static function record(string $ipAddress, string $userAgent, bool $success): void
    {
        static::create([
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'success' => $success,
            'attempted_at' => now(),
        ]);

        // حذف المحاولات القديمة (أكثر من 30 يوم)
        static::where('attempted_at', '<', now()->subDays(30))->delete();
    }

    /**
     * التحقق من عدد المحاولات الفاشلة من نفس IP
     */
    public static function getFailedAttemptsCount(string $ipAddress, int $minutes = 15): int
    {
        return static::where('ip_address', $ipAddress)
            ->where('success', false)
            ->where('attempted_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * التحقق من حظر IP
     */
    public static function isIpBlocked(string $ipAddress, int $maxAttempts = 5, int $minutes = 15): bool
    {
        return static::getFailedAttemptsCount($ipAddress, $minutes) >= $maxAttempts;
    }
}

