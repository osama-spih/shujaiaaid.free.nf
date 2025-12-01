<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Models\AdminLoginAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;

class AdminAuthController extends Controller
{
    public function __invoke(AdminLoginRequest $request): JsonResponse
    {
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown';

        // التحقق من حظر IP
        if (AdminLoginAttempt::isIpBlocked($ipAddress, 5, 15)) {
            AdminLoginAttempt::record($ipAddress, $userAgent, false);
            
            Log::warning('Blocked login attempt from blocked IP', [
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            return response()->json([
                'message' => 'تم حظر هذا العنوان مؤقتاً بسبب محاولات دخول فاشلة متعددة. يرجى المحاولة لاحقاً.',
            ], 429);
        }

        $configuredPassword = config('services.admin.password');
        $panelToken = config('services.admin.panel_token');

        if (!$configuredPassword || !$panelToken) {
            AdminLoginAttempt::record($ipAddress, $userAgent, false);
            
            Log::error('Admin credentials not configured');
            
            throw new SessionNotFoundException('يجب ضبط ADMIN_PANEL_PASSWORD و ADMIN_ACCESS_TOKEN داخل ملف البيئة.');
        }

        $providedPassword = $request->validated('password');
        $isValid = hash_equals($configuredPassword, $providedPassword);

        // تسجيل محاولة الدخول
        AdminLoginAttempt::record($ipAddress, $userAgent, $isValid);

        if (!$isValid) {
            $failedAttempts = AdminLoginAttempt::getFailedAttemptsCount($ipAddress, 15);
            $remainingAttempts = max(0, 5 - $failedAttempts);

            Log::warning('Failed admin login attempt', [
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
                'failed_attempts' => $failedAttempts,
                'remaining' => $remainingAttempts,
            ]);

            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة.',
                'remaining_attempts' => $remainingAttempts,
            ], 401);
        }

        // تسجيل دخول ناجح
        Log::info('Successful admin login', [
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'timestamp' => now(),
        ]);

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح.',
            'token' => $panelToken,
            'expires_at' => now()->addHours(24)->toIso8601String(), // Token صالح لمدة 24 ساعة
        ]);
    }
}
