<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FamilyMember>
 */
class FamilyMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $relations = ['زوجة', 'زوج', 'ابن', 'ابنة', 'أب', 'أم', 'أخ', 'أخت', 'جد', 'جدة'];
        $relation = fake('ar_SA')->randomElement($relations);
        $healthStatuses = ['سليم', 'معاف', 'مصاب', 'آخر'];

        return [
            'member_name' => fake('ar_SA')->name(),
            'relation' => $relation,
            'national_id' => fake()->numerify('##########'),
            'phone' => '05'.fake()->numerify('########'),
            'birth_date' => fake()->date(),
            'health_status' => fake('ar_SA')->randomElement($healthStatuses),
            'is_guardian' => $relation === 'زوجة',
            'needs_care' => fake()->boolean(20),
            'notes' => fake('ar_SA')->sentence(),
        ];
    }
}
