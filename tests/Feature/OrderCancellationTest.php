<?php

use App\Models\Order;
use App\Models\OrderItemCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('PB can request cancellation for an entire order and PF approval reduces its order value', function () {
    $baliAdmin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $order = Order::create([
        'order_id' => 'ORDER-CANCEL-REQUEST',
        'guest_name' => 'Cancellation Guest',
        'total_order' => 400,
        'status' => 'Proses Produksi',
    ]);
    $productionItem = $order->items()->create([
        'product' => 'Production Item',
        'qty' => 2,
        'qty_completed' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);
    $newItem = $order->items()->create([
        'product' => 'New Item',
        'qty' => 1,
        'price' => 200,
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);

    $this->actingAs($baliAdmin)
        ->get(route('orders.show', $order))
        ->assertSee('Cetak nota', false)
        ->assertDontSee('Ajukan cancel seluruh order', false);
    $this->get(route('orders.edit', $order))
        ->assertSee('Ajukan cancel seluruh order', false);
    $this->get(route('orders.index'))
        ->assertSee('aria-label="Ajukan cancel order ORDER-CANCEL-REQUEST"', false);

    $this->post(route('orders.cancellations.store', $order))
        ->assertRedirect(route('orders.edit', $order));

    $this->get(route('orders.show', $order))
        ->assertDontSeeText('Permintaan cancel order sedang menunggu keputusan PF.')
        ->assertDontSee('Ajukan cancel seluruh order', false);
    $this->get(route('orders.edit', $order))
        ->assertSeeText('Permintaan cancel order sedang menunggu keputusan PF.')
        ->assertDontSee('Ajukan cancel seluruh order', false);
    $this->get(route('orders.index'))
        ->assertSeeText('Permintaan cancel order sedang menunggu keputusan PF.')
        ->assertDontSee('aria-label="Ajukan cancel order ORDER-CANCEL-REQUEST"', false);

    $cancellations = OrderItemCancellation::query()
        ->whereIn('order_item_id', [$productionItem->id, $newItem->id])
        ->orderBy('order_item_id')
        ->get();

    expect($cancellations)->toHaveCount(2)
        ->and($cancellations->every(fn (OrderItemCancellation $cancellation): bool => $cancellation->decision === 'pending'))->toBeTrue()
        ->and((float) $order->fresh()->total_order)->toBe(400.0);

    foreach ($cancellations as $cancellation) {
        $this->actingAs($factoryAdmin)
            ->patch(route('order-item-cancellations.update', $cancellation), [
                'decision' => 'approved',
            ])
            ->assertRedirect();
    }

    expect((float) $order->fresh()->total_order)->toBe(0.0)
        ->and($order->fresh()->status)->toBe('Cancel')
        ->and($productionItem->fresh()->qty)->toBe(0)
        ->and($newItem->fresh()->qty)->toBe(0);
});

test('PF cannot request cancellation of a PB customer order', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $order = Order::create([
        'order_id' => 'ORDER-CANCEL-UNAUTHORIZED',
        'guest_name' => 'Cancellation Guest',
        'total_order' => 100,
        'status' => 'None',
    ]);
    $order->items()->create([
        'product' => 'Cancellation Item',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('orders.cancellations.store', $order))
        ->assertForbidden();

    $this->assertDatabaseCount('order_item_cancellations', 0);
});
