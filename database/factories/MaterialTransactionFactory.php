<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialTransaction>
 */
class MaterialTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'created_by_id' => User::factory(),
            'type' => 'in',
            'quantity' => fake()->randomFloat(3, 1, 100),
            'unit_price' => fake()->randomFloat(2, 0, 100000),
            'note' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
