<?php

namespace Database\Factories;

use App\Models\AttendancePunishmentRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePunishmentRule>
 */
class AttendancePunishmentRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'applies_to' => 'all',
            'attendance_below' => 70,
            'punishment' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
