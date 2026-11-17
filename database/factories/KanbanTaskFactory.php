<?php

namespace Database\Factories;

use App\Models\KanbanTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KanbanTask>
 */
class KanbanTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignee_id' => User::factory(),
            'created_by_id' => User::factory(),
            'task_date' => now()->toDateString(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => 'plan',
            'position' => 0,
        ];
    }
}
