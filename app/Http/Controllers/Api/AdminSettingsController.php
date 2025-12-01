<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function getUpdateStatus(): JsonResponse
    {
        $enabled = Setting::get('data_update_enabled', '1') === '1';

        return response()->json([
            'message' => 'تم جلب الحالة بنجاح.',
            'data' => [
                'enabled' => $enabled,
            ],
        ]);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::set('data_update_enabled', $request->enabled ? '1' : '0');

        return response()->json([
            'message' => $request->enabled 
                ? 'تم تفعيل تحديث البيانات بنجاح.' 
                : 'تم تعطيل تحديث البيانات بنجاح.',
            'data' => [
                'enabled' => $request->enabled,
            ],
        ]);
    }
}
