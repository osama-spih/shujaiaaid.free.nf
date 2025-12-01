<?php

namespace Database\Seeders;

use App\Models\FamilyMember;
use App\Models\Identity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IdentitySeeder extends Seeder
{
    public function run(): void
    {
        // تنظيف البيانات القديمة
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        FamilyMember::truncate();
        Identity::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // إنشاء بيانات جديدة مع الحقول الجديدة
        Identity::factory()
            ->count(20)
            ->create()
            ->each(function (Identity $identity): void {
                $familyMembers = FamilyMember::factory()
                    ->count(random_int(1, 6))
                    ->create([
                        'identity_id' => $identity->id,
                    ]);

                $identity->forceFill([
                    'family_members_count' => $familyMembers->count(),
                    'needs_review' => fake()->boolean(30), // 30% يحتاجون مراجعة
                ])->save();
            });

        $this->command->info('تم تنظيف القاعدة وإضافة ' . Identity::count() . ' مستفيد مع ' . FamilyMember::count() . ' فرد من الأسرة.');
    }
}
