<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitPublicApi
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'public-api:' . $request->ip();

        $executed = RateLimiter::attempt(
            $key,
            $perMinute = 10, // 10 طلبات في الدقيقة
            function () use ($next, $request) {
                return $next($request);
            }
        );

        if (!$executed) {
            return response()->json([
                'message' => 'تم تجاوز الحد المسموح من الطلبات. يرجى المحاولة لاحقاً.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        return $executed;
    }
}

