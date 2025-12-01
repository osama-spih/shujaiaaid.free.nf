<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Identity>
 */
class IdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $maritalStatuses = ['أعزب', 'متزوج', 'منفصل', 'أرمل', 'مطلقة', 'متعدد الزوجات', 'مفقود', 'شهيد', 'متوفى', 'أسير'];
        $maritalStatus = fake('ar_SA')->randomElement($maritalStatuses);
        $isMarried = in_array($maritalStatus, ['متزوج', 'متعدد الزوجات']);

        $housingTypes = ['ملك', 'إيجار', 'سكن حكومي', 'سكن خيري', 'مستأجر', 'آخر'];
        $healthStatuses = ['سليم', 'معاف', 'مصاب', 'آخر'];

        return [
            'national_id' => fake()->unique()->numerify('##########'),
            'full_name' => fake('ar_SA')->name(),
            'phone' => '05'.fake()->numerify('########'),
            'marital_status' => $maritalStatus,
            'family_members_count' => $maritalStatus === 'أعزب' ? 1 : fake()->numberBetween(2, 8),
            'spouse_name' => $isMarried ? fake('ar_SA')->name() : null,
            'spouse_phone' => $isMarried ? '05'.fake()->numerify('########') : null,
            'spouse_national_id' => $isMarried ? fake()->numerify('##########') : null,
            'primary_address' => fake('ar_SA')->address(),
            'previous_address' => fake()->boolean(60) ? fake('ar_SA')->address() : null,
            'housing_type' => fake('ar_SA')->randomElement($housingTypes),
            'job_title' => fake('ar_SA')->jobTitle(),
            'health_status' => fake('ar_SA')->randomElement($healthStatuses),
            'notes' => fake('ar_SA')->sentence(),
            'needs_review' => fake()->boolean(),
            'entered_at' => now()->subDays(fake()->numberBetween(1, 60)),
            'last_verified_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ];
    }
}
