<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PieceworkAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PieceworkAssignment>
 */
class PieceworkAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'product_name' => fake()->words(2, true),
            'target_quantity' => fake()->numberBetween(1, 20),
            'unit_price' => null,
            'target_deadline' => today()->addWeek()->toDateString(),
        ];
    }
}
