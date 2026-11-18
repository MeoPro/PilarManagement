<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\OrderItemService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItemService>
 */
class OrderItemServiceFactory extends Factory
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
            'quantity' => 1,
            'status' => 'Service',
            'severity' => 'Ringan',
            'note' => null,
        ];
    }
}
