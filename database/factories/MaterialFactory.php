<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_unit' => User::UNIT_BALI,
            'name' => fake()->unique()->word(),
            'unit' => 'kg',
            'unit_price' => fake()->randomFloat(2, 0, 100000),
        ];
    }
}
