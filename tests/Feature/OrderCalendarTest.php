<?php

use App\Models\Guest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('calendar redirects unauthenticated visitors to login', function () {
    $this->get(route('calendar.index'))
        ->assertRedirect(route('login'));
});

test('calendar marks order registration and delivery target dates', function () {
    $this->travelTo('2026-03-10 11:00:00');
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Calendar Guest']);
    $order = Order::create([
        'order_id' => 'CAL-INCOMING',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100,
        'deadline' => '2026-03-18',
        'status' => 'None',
    ]);

    $response = $this->actingAs($admin)->get(route('calendar.index', ['month' => '2026-03']));

    $response->assertSeeText('Order masuk: CAL-INCOMING')
        ->assertSeeText('Target kirim: CAL-INCOMING')
        ->assertSee(route('orders.show', $order), false);
});

test('calendar does not mark a cancelled order for delivery', function () {
    $this->travelTo('2026-03-10 11:00:00');
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Cancelled Calendar Guest']);
    Order::create([
        'order_id' => 'CAL-CANCELLED',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100,
        'deadline' => '2026-03-18',
        'status' => 'Cancel',
    ]);

    $response = $this->actingAs($admin)->get(route('calendar.index', ['month' => '2026-03']));

    $response->assertSeeText('Order masuk: CAL-CANCELLED')
        ->assertDontSeeText('Target kirim: CAL-CANCELLED');
});

test('order and production status changes appear on the calendar', function () {
    Storage::fake('public');
    $this->travelTo('2026-03-10 11:00:00');
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Calendar Status Guest']);
    $order = Order::create([
        'order_id' => 'CAL-STATUS',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100,
        'deadline' => '2026-03-20',
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Side Table',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'None',
    ]);

    $this->actingAs($admin)
        ->put(route('orders.update', $order), [
            'order_id' => $order->order_id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'deadline' => '2026-03-22',
            'items' => [[
                'id' => $item->id,
                'product' => $item->product,
                'qty' => 1,
                'price' => 100,
                'source' => 'Order',
                'status' => 'None',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $response = $this->actingAs($admin)
        ->from(route('orders.index'))
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $item->id,
                'status' => 'Produksi',
                'status_images' => [
                    UploadedFile::fake()->create('side-table-mockup.jpg', 100, 'image/jpeg'),
                ],
            ]],
        ]);

    $response->assertRedirect(route('orders.index'));
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'order_status_changed',
        'description' => 'CAL-STATUS: None -> Proses Produksi (berdasarkan status seluruh produk)',
    ]);
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'type' => 'item_status_changed',
        'description' => 'CAL-STATUS - Side Table: None -> Produksi',
    ]);
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'deadline_changed',
        'description' => 'CAL-STATUS: 2026-03-20 -> 2026-03-22',
    ]);

    $calendarResponse = $this->get(route('calendar.index', ['month' => '2026-03']));

    $calendarResponse->assertSeeText('CAL-STATUS: None -> Proses Produksi')
        ->assertSeeText('CAL-STATUS - Side Table: None -> Produksi')
        ->assertSeeText('Target pengiriman diubah')
        ->assertSeeText('Perubahan status')
        ->assertSeeText('Perubahan lain');
});

test('formatted prices are normalized before order validation', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Formatted Price Guest']);

    $response = $this->actingAs($admin)->post(route('orders.store'), [
        'order_id' => 'ORD-FORMATTED-PRICE',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'status' => 'None',
        'items' => [[
            'product' => 'Formatted Price Product',
            'dimensions' => '10 10 10',
            'qty' => 2,
            'price' => '1,250.50',
            'source' => 'Stock',
            'status' => 'None',
        ]],
    ]);

    $response->assertRedirect(route('orders.index'));
    $order = Order::with('items')->where('order_id', 'ORD-FORMATTED-PRICE')->firstOrFail();

    expect($order->items->first()->price)->toBe('1250.50')
        ->and($order->items->first()->dimensions)->toBe('10x10x10')
        ->and($order->total_order)->toBe('2501.00');
});

test('order validation rejects malformed price grouping', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Invalid Price Guest']);

    $response = $this->actingAs($admin)
        ->from(route('orders.create'))
        ->post(route('orders.store'), [
            'order_id' => 'ORD-INVALID-PRICE',
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'status' => 'None',
            'items' => [[
                'product' => 'Invalid Price Product',
                'dimensions' => '10x10x10',
                'qty' => 1,
                'price' => '1,25',
                'source' => 'Stock',
                'status' => 'None',
            ]],
        ]);

    $response->assertSessionHasErrors('items.0.price');
    $this->assertDatabaseMissing('orders', [
        'order_id' => 'ORD-INVALID-PRICE',
    ]);
});
