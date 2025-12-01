<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Response;

class AdminToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $providedToken = $request->bearerToken() ?: $request->header('X-Admin-Token');
        $expectedToken = config('services.admin.panel_token');
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown';

        if (empty($expectedToken)) {
            Log::error('Admin token not configured');
            throw new SessionNotFoundException('لم يتم ضبط مفتاح الإدارة، يرجى تعيين ADMIN_ACCESS_TOKEN في ملف البيئة.');
        }

        if (!$providedToken) {
            Log::warning('Admin access attempt without token', [
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
                'path' => $request->path(),
            ]);

            return response()->json([
                'message' => 'ليست لديك صلاحية للوصول إلى هذه الواجهة.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (!hash_equals($expectedToken, $providedToken)) {
            Log::warning('Admin access attempt with invalid token', [
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
                'path' => $request->path(),
                'token_length' => strlen($providedToken),
            ]);

            return response()->json([
                'message' => 'ليست لديك صلاحية للوصول إلى هذه الواجهة.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // تسجيل الوصول الناجح (اختياري - يمكن تعطيله في الإنتاج)
        if (config('app.debug')) {
            Log::info('Admin access granted', [
                'ip' => $ipAddress,
                'path' => $request->path(),
            ]);
        }

        return $next($request);
    }
}
