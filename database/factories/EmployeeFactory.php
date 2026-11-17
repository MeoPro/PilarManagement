<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'photo_path' => 'employees/'.fake()->uuid().'.jpg',
            'address' => fake()->address(),
            'employee_type' => 'Harian',
            'daily_wage' => 100000,
            'started_at' => today()->subMonth()->toDateString(),
            'status' => 'Aktif',
        ];
    }
}
