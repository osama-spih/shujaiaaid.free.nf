<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleAdminLogin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'admin-login:' . $request->ip();

        // 5 محاولات كل 15 دقيقة
        $executed = RateLimiter::attempt(
            $key,
            $perMinutes = 5,
            function () use ($next, $request) {
                return $next($request);
            },
            $decayMinutes = 15
        );

        if (!$executed) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = ceil($seconds / 60);

            return response()->json([
                'message' => "تم تجاوز عدد المحاولات المسموحة. يرجى المحاولة بعد {$minutes} دقيقة.",
                'retry_after' => $seconds,
            ], 429);
        }

        return $executed;
    }
}

