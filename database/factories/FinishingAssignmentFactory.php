<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\FinishingAssignment;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinishingAssignment>
 */
class FinishingAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'employee_id' => Employee::factory(),
            'assigned_by_id' => User::factory(),
            'target_quantity' => 1,
            'completed_quantity' => 0,
            'cancelled_quantity' => 0,
            'is_rework' => false,
            'started_at' => now(),
        ];
    }
}
