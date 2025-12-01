<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PreventSqlInjection
{
    /**
     * SQL Injection patterns to detect
     */
    private const SQL_INJECTION_PATTERNS = [
        // SQL Keywords
        '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|EXECUTE|UNION|SCRIPT|SCRIPT|TRUNCATE|REPLACE)\b)/i',
        // SQL Operators
        '/(\b(OR|AND)\s+\d+\s*=\s*\d+)/i',
        '/(\b(OR|AND)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?)/i',
        // SQL Comments
        '/(--|#|\/\*|\*\/)/',
        // SQL Functions
        '/(\b(CHAR|ASCII|SUBSTRING|CONCAT|CAST|CONVERT|BENCHMARK|SLEEP|WAITFOR|DELAY)\s*\()/i',
        // SQL Injection techniques
        '/(\'\s*(OR|AND)\s*[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?)/i',
        '/(\'\s*OR\s*[\'"]?[\'"]?\s*=\s*[\'"]?[\'"]?)/i',
        '/(\'\s*;\s*--)/i',
        '/(\'\s*;\s*#)/i',
        '/(\'\s*;\s*\/\*)/i',
        // Union based
        '/(UNION\s+ALL\s+SELECT)/i',
        '/(UNION\s+SELECT)/i',
        // Boolean based
        '/(\'\s*OR\s*[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?)/i',
        // Time-based
        '/(SLEEP\s*\()/i',
        '/(WAITFOR\s+DELAY)/i',
        '/(BENCHMARK\s*\()/i',
        // Error-based
        '/(EXTRACTVALUE\s*\()/i',
        '/(UPDATEXML\s*\()/i',
        '/(XPATH\s*\()/i',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check all input data
        $allInput = array_merge(
            $request->all(),
            $request->query->all(),
            $request->request->all()
        );

        foreach ($allInput as $key => $value) {
            if (is_string($value)) {
                if ($this->isSqlInjectionAttempt($value)) {
                    $ipAddress = $request->ip();
                    $userAgent = $request->userAgent() ?? 'Unknown';
                    
                    Log::warning('SQL Injection attempt detected', [
                        'ip' => $ipAddress,
                        'user_agent' => $userAgent,
                        'input_key' => $key,
                        'input_value' => substr($value, 0, 100), // Log first 100 chars only
                        'path' => $request->path(),
                        'method' => $request->method(),
                    ]);

                    return response()->json([
                        'message' => 'تم رفض الطلب بسبب محتوى غير مسموح.',
                    ], 400);
                }
            } elseif (is_array($value)) {
                // Recursively check arrays
                if ($this->checkArrayForSqlInjection($value)) {
                    $ipAddress = $request->ip();
                    $userAgent = $request->userAgent() ?? 'Unknown';
                    
                    Log::warning('SQL Injection attempt detected in array', [
                        'ip' => $ipAddress,
                        'user_agent' => $userAgent,
                        'input_key' => $key,
                        'path' => $request->path(),
                        'method' => $request->method(),
                    ]);

                    return response()->json([
                        'message' => 'تم رفض الطلب بسبب محتوى غير مسموح.',
                    ], 400);
                }
            }
        }

        return $next($request);
    }

    /**
     * Check if a string contains SQL injection patterns
     */
    private function isSqlInjectionAttempt(string $value): bool
    {
        // Skip empty values
        if (empty(trim($value))) {
            return false;
        }

        // Check against all patterns
        foreach (self::SQL_INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively check arrays for SQL injection
     */
    private function checkArrayForSqlInjection(array $array): bool
    {
        foreach ($array as $key => $value) {
            if (is_string($value)) {
                if ($this->isSqlInjectionAttempt($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                if ($this->checkArrayForSqlInjection($value)) {
                    return true;
                }
            }
        }

        return false;
    }
}

