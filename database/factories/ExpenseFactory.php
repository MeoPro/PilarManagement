<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 10);
        $unitPrice = fake()->randomFloat(2, 1000, 100000);

        return [
            'business_unit' => User::UNIT_BALI,
            'description' => fake()->sentence(3),
            'quantity' => $quantity,
            'unit' => 'kali',
            'unit_price' => $unitPrice,
            'total' => round($quantity * $unitPrice, 2),
            'occurred_at' => today(),
            'note' => null,
            'created_by_id' => User::factory(),
        ];
    }
}
