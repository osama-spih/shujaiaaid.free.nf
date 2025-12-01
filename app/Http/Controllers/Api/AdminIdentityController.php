<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminIdentityRequest;
use App\Http\Resources\IdentityResource;
use App\Models\Identity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AdminIdentityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim();
        $perPage = (int) $request->integer('per_page', 5000);
        $perPage = max(5, min($perPage, 5000)); // Limit to 5000 per page for performance

        // Security: Sanitize search input - remove SQL injection patterns
        if ($search) {
            // Remove potentially dangerous characters but keep Arabic and basic alphanumeric
            $search = preg_replace('/[<>"\';(){}[\]\\\]/', '', $search);
            $search = trim($search);
            
            // Limit search length
            $search = mb_substr($search, 0, 100, 'UTF-8');
        }

        // Optimize query: select only needed fields, don't load family members for list
        // Security: Use parameterized queries (Eloquent does this automatically)
        $identities = Identity::query()
            ->select([
                'id', 'national_id', 'full_name', 'phone', 'marital_status',
                'family_members_count', 'spouse_name', 'spouse_phone', 'spouse_national_id',
                'primary_address', 'previous_address', 'housing_type', 'job_title',
                'health_status', 'notes', 'needs_review', 'entered_at',
                'last_verified_at', 'created_at', 'updated_at'
            ])
            ->when($search, fn ($query) => $query->where(function ($builder) use ($search) {
                // Security: Eloquent automatically uses parameterized queries
                $builder->where('national_id', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'message' => 'تم جلب السجلات.',
            'data' => IdentityResource::collection($identities),
            'meta' => [
                'current_page' => $identities->currentPage(),
                'last_page' => $identities->lastPage(),
                'total' => $identities->total(),
            ],
        ]);
    }

    public function store(AdminIdentityRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            $familyMembers = Arr::pull($payload, 'family_members', []);

            // التحقق من عدد أفراد الأسرة
            if (count($familyMembers) > 30) {
                return response()->json([
                    'message' => 'عدد أفراد الأسرة لا يمكن أن يزيد عن 30.',
                ], 422);
            }

            $identity = \DB::transaction(function () use ($payload, $familyMembers) {
                $identity = Identity::create($payload);
                $this->syncFamilyMembers($identity, $familyMembers);
                return $identity;
            });

            return response()->json([
                'message' => 'تم إضافة المستفيد بنجاح.',
                'data' => new IdentityResource($identity->fresh('familyMembers')),
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            \Log::error('Database error in store: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ أثناء حفظ البيانات. يرجى المحاولة مرة أخرى.',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Error in store: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ غير متوقع. يرجى المحاولة مرة أخرى.',
            ], 500);
        }
    }

    public function show(Identity $identity): JsonResponse
    {
        $identity->load('familyMembers');

        return response()->json([
            'message' => 'تم جلب بيانات المستفيد.',
            'data' => new IdentityResource($identity),
        ]);
    }

    public function update(AdminIdentityRequest $request, Identity $identity): JsonResponse
    {
        try {
            $payload = $request->validated();
            $familyMembers = Arr::pull($payload, 'family_members', []);

            // التحقق من عدد أفراد الأسرة
            if (count($familyMembers) > 30) {
                return response()->json([
                    'message' => 'عدد أفراد الأسرة لا يمكن أن يزيد عن 30.',
                ], 422);
            }

            \DB::transaction(function () use ($identity, $payload, $familyMembers) {
                $identity->update($payload);
                $this->syncFamilyMembers($identity, $familyMembers);
            });

            return response()->json([
                'message' => 'تم تحديث البيانات بنجاح.',
                'data' => new IdentityResource($identity->fresh('familyMembers')),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            \Log::error('Database error in update: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ أثناء تحديث البيانات. يرجى المحاولة مرة أخرى.',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Error in update: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ غير متوقع. يرجى المحاولة مرة أخرى.',
            ], 500);
        }
    }

    public function destroy(Identity $identity): JsonResponse
    {
        $identity->delete();

        return response()->json([
            'message' => 'تم حذف السجل.',
        ]);
    }

    protected function syncFamilyMembers(Identity $identity, array $familyMembers): void
    {
        $idsToKeep = collect($familyMembers)
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if (!empty($idsToKeep)) {
            $identity->familyMembers()
                ->whereNotIn('id', $idsToKeep)
                ->delete();
        } else {
            $identity->familyMembers()->delete();
        }

        foreach ($familyMembers as $member) {
            $memberData = Arr::only($member, [
                'member_name',
                'relation',
                'national_id',
                'phone',
                'birth_date',
                'is_guardian',
                'needs_care',
                'health_status',
                'notes',
            ]);

            // تنظيف البيانات
            if (isset($memberData['notes'])) {
                $memberData['notes'] = mb_substr($memberData['notes'], 0, 500, 'UTF-8');
            }

            // التحقق من القيم المنطقية
            $memberData['is_guardian'] = (bool) ($memberData['is_guardian'] ?? false);
            $memberData['needs_care'] = (bool) ($memberData['needs_care'] ?? false);

            if (!empty($member['id'])) {
                $identity->familyMembers()
                    ->where('id', $member['id'])
                    ->update($memberData);
            } else {
                $identity->familyMembers()->create($memberData);
            }
        }

        $identity->update([
            'family_members_count' => $identity->familyMembers()->count(),
        ]);
    }
}
