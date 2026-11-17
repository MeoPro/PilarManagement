<?php

namespace Database\Factories;

use App\Models\AdminAttendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAttendance>
 */
class AdminAttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'attendance_date' => today()->toDateString(),
        ];
    }
}
