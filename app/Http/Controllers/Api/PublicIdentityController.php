<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicIdentitySearchRequest;
use App\Http\Requests\PublicIdentityUpsertRequest;
use App\Http\Resources\IdentityResource;
use App\Models\Identity;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class PublicIdentityController extends Controller
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

    public function search(PublicIdentitySearchRequest $request): JsonResponse
    {
        $updateEnabled = Setting::get('data_update_enabled', '1') === '1';

        if (!$updateEnabled) {
            return response()->json([
                'message' => 'انتهى تحديث البيانات.',
                'update_disabled' => true,
            ], 403);
        }

        $identity = Identity::query()
            ->with('familyMembers')
            ->where('national_id', $request->validated('national_id'))
            ->first();

        if (!$identity) {
            return response()->json([
                'message' => 'غير مسجل مسبقاً، يرجى تعبئة بياناتك.',
            ], 404);
        }

        return response()->json([
            'message' => 'تم جلب البيانات بنجاح.',
            'data' => new IdentityResource($identity),
        ]);
    }

    public function upsert(PublicIdentityUpsertRequest $request): JsonResponse
    {
        $updateEnabled = Setting::get('data_update_enabled', '1') === '1';

        if (!$updateEnabled) {
            return response()->json([
                'message' => 'انتهى تحديث البيانات.',
                'update_disabled' => true,
            ], 403);
        }

        try {
            $validated = $request->validated();
            $familyMembersPayload = collect($validated['family_members'] ?? []);

            // التحقق من عدد أفراد الأسرة
            if ($familyMembersPayload->count() > 30) {
                return response()->json([
                    'message' => 'عدد أفراد الأسرة لا يمكن أن يزيد عن 30.',
                ], 422);
            }

            // استخدام transaction لضمان سلامة البيانات
            $identity = \DB::transaction(function () use ($validated, $familyMembersPayload) {
                $identity = Identity::updateOrCreate(
                    ['national_id' => $validated['national_id']],
                    Arr::except($validated, ['family_members'])
                );

                // حذف أفراد الأسرة الحاليين
                $identity->familyMembers()->delete();

                // إضافة أفراد الأسرة الجدد
                if ($familyMembersPayload->isNotEmpty()) {
                    $membersData = $familyMembersPayload->map(function ($member) {
                        return [
                            'member_name' => $member['member_name'] ?? '',
                            'relation' => $member['relation'] ?? '',
                            'national_id' => $member['national_id'] ?? null,
                            'phone' => $member['phone'] ?? null,
                            'birth_date' => $member['birth_date'] ?? null,
                            'is_guardian' => (bool) ($member['is_guardian'] ?? false),
                            'needs_care' => (bool) ($member['needs_care'] ?? false),
                            'health_status' => $member['health_status'] ?? null,
                            'notes' => isset($member['notes']) ? mb_substr($member['notes'], 0, 500, 'UTF-8') : null,
                        ];
                    })->toArray();

                    $identity->familyMembers()->createMany($membersData);
                }

                // تحديث عدد أفراد الأسرة
                $identity->forceFill([
                    'family_members_count' => $familyMembersPayload->count(),
                    'needs_review' => true,
                ])->save();

                return $identity;
            });

            return response()->json([
                'message' => 'تم حفظ البيانات بنجاح وسيتم مراجعتها.',
                'data' => new IdentityResource($identity->fresh('familyMembers')),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            \Log::error('Database error in upsert: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ أثناء حفظ البيانات. يرجى المحاولة مرة أخرى.',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Error in upsert: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ غير متوقع. يرجى المحاولة مرة أخرى.',
            ], 500);
        }
    }
}
