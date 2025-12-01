<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Add SQL Injection protection to all API routes
        $middleware->api(prepend: [
            \App\Http\Middleware\PreventSqlInjection::class,
        ]);
        $middleware->alias([
            'admin.token' => \App\Http\Middleware\AdminToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // في الإنتاج، إخفاء تفاصيل الأخطاء
        if (config('app.env') === 'production') {
            // إخفاء تفاصيل الأخطاء في الإنتاج
            $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
                return $request->expectsJson();
            });
            
            // معالجة الأخطاء بشكل آمن
            $exceptions->render(function (\Throwable $e, $request) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'حدث خطأ في الخادم. يرجى المحاولة لاحقاً.',
                        'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
                    ], 500);
                }
            });
        }
        
        // تسجيل جميع الأخطاء
        $exceptions->report(function (\Throwable $e) {
            \Log::error('Exception occurred', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);
        });
    })->create();
