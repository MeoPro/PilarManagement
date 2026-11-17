<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubcontractorAssignment>
 */
class SubcontractorAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => null,
            'subcontractor_id' => Subcontractor::factory(),
            'created_by_id' => null,
            'assigned_quantity' => 1,
            'qty_completed' => 0,
            'status' => 'Produksi',
            'cancelled_at' => null,
        ];
    }

    public function forOrderItem(OrderItem $orderItem): static
    {
        return $this->state(fn (array $attributes): array => [
            'order_item_id' => $orderItem->id,
        ]);
    }
}
