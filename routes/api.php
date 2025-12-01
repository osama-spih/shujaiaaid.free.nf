<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminIdentityController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\PublicIdentityController;
use Illuminate\Support\Facades\Route;

// Public routes with rate limiting
Route::middleware(['throttle:10,1'])->group(function (): void {
    Route::post('/search', [PublicIdentityController::class, 'search']);
});

Route::middleware(['throttle:5,1'])->group(function (): void {
    Route::post('/profile', [PublicIdentityController::class, 'upsert']);
});

Route::middleware(['throttle:30,1'])->group(function (): void {
    Route::get('/update-status', [PublicIdentityController::class, 'getUpdateStatus']);
});

// Health Check endpoint (public, no throttle)
Route::get('/health', function () {
    try {
        // Check database connection
        \DB::connection()->getPdo();
        $dbStatus = 'connected';
    } catch (\Exception $e) {
        $dbStatus = 'disconnected';
    }
    
    // Check storage permissions
    $storageWritable = is_writable(storage_path());
    
    // Check queue connection
    $queueStatus = config('queue.default') === 'database' ? 'ok' : 'unknown';
    
    // Get disk space (if available)
    $diskFree = disk_free_space(storage_path());
    $diskTotal = disk_total_space(storage_path());
    $diskUsagePercent = $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 2) : 0;
    
    $status = ($dbStatus === 'connected' && $storageWritable) ? 'ok' : 'degraded';
    
    return response()->json([
        'status' => $status,
        'timestamp' => now()->toIso8601String(),
        'checks' => [
            'database' => $dbStatus,
            'storage' => $storageWritable ? 'writable' : 'not writable',
            'queue' => $queueStatus,
        ],
        'system' => [
            'disk_usage_percent' => $diskUsagePercent,
            'disk_free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
            'disk_total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
        ],
    ], $status === 'ok' ? 200 : 503);
});

// Admin login with strict rate limiting and custom middleware
Route::middleware([\App\Http\Middleware\ThrottleAdminLogin::class])->group(function (): void {
    Route::post('/admin/login', AdminAuthController::class);
});

Route::middleware('admin.token')
    ->prefix('admin')
    ->group(function (): void {
        Route::apiResource('identities', AdminIdentityController::class);
        Route::get('/settings/update-status', [AdminSettingsController::class, 'getUpdateStatus']);
        Route::put('/settings/update-status', [AdminSettingsController::class, 'updateStatus']);
        Route::get('/export/excel', [\App\Http\Controllers\Api\AdminExportController::class, 'exportExcel']);
        Route::post('/export/excel/async', [\App\Http\Controllers\Api\AdminExportController::class, 'exportExcelAsync']);
        Route::post('/export/pdf/async', [\App\Http\Controllers\Api\AdminExportController::class, 'exportPdfAsync']);
        Route::get('/export/status/{jobId}', [\App\Http\Controllers\Api\AdminExportController::class, 'getExportStatus']);
        Route::get('/export/download/{jobId}', [\App\Http\Controllers\Api\AdminExportController::class, 'downloadExport']);
        Route::post('/import/excel', [\App\Http\Controllers\Api\AdminExportController::class, 'importExcel']);
        Route::post('/import/excel/async', [\App\Http\Controllers\Api\AdminExportController::class, 'importExcelAsync']);
        Route::get('/import/status/{jobId}', [\App\Http\Controllers\Api\AdminExportController::class, 'getImportStatus']);
    });

