<?php

use App\Models\Guest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('Factory users cannot bypass production workflow through legacy status endpoints', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Automatic Order Status Guest']);
    $order = Order::create([
        'order_id' => 'AUTOMATIC-ORDER-STATUS',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 20000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Chair',
        'qty' => 2,
        'qty_completed' => 0,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $item->id,
                'status' => 'None',
            ]],
        ])
        ->assertForbidden();

    $this->patch(route('order-products.update', $item), [
        'items' => [
            $item->id => ['status' => 'Proses Kirim', 'qty_completed' => 2],
        ],
    ])->assertForbidden();

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'Proses Produksi',
    ]);
    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'qty_completed' => 0,
    ]);
});

test('order detail requires a new status photo and saves it without replacing the product image', function () {
    Storage::fake('public');
    $salesAdmin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Status Photo Guest']);
    $order = Order::create([
        'order_id' => 'STATUS-PHOTO-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Status Photo Chair',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'None',
        'image' => 'orders/products/product.jpg',
    ]);

    $this->actingAs($salesAdmin)
        ->get(route('orders.edit', $order))
        ->assertSee('name="items[0][status_images][]"', false)
        ->assertSee('multiple', false);

    $this->from(route('orders.edit', $order))
        ->patch(route('orders.production.update', $order), [
            'items' => [['id' => $item->id, 'status' => 'Produksi']],
        ])
        ->assertSessionHasErrors('items.0.status_images');

    expect($item->fresh()->status)->toBe('None');

    $this->from(route('orders.edit', $order))
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $item->id,
                'status' => 'Produksi',
                'status_images' => [
                    UploadedFile::fake()->create('production-one.jpg', 100, 'image/jpeg'),
                    UploadedFile::fake()->create('production-two.jpg', 100, 'image/jpeg'),
                ],
            ]],
        ])
        ->assertRedirect(route('orders.edit', $order));

    expect($item->fresh()->image)->toBe('orders/products/product.jpg')
        ->and($item->fresh()->status_evidence_images)->toHaveCount(2);
    foreach ($item->fresh()->status_evidence_images as $statusImage) {
        Storage::disk('public')->assertExists($statusImage);
    }
    $this->get(route('orders.show', $order))
        ->assertSee('alt="Mockup Status Photo Chair"', false);
});

test('product queue requires a photo before moving a product to distribution', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Queue Status Photo Guest']);
    $order = Order::create([
        'order_id' => 'QUEUE-STATUS-PHOTO-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Queue Status Photo Chair',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
        ])
        ->assertSessionHasErrors('factory_drawing_image');

    expect($item->fresh()->factory_drawing_image)->toBeNull();
    $this->assertDatabaseCount('order_events', 0);
});

test('production update stores multiple status evidence photos', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('orders.store'), [
            'order_id' => 'MULTIPLE-STATUS-PHOTOS',
            'guest_name' => 'Multiple Status Photos Guest',
            'guest_source' => 'Website',
            'order_type' => 'Bali',
            'status' => 'None',
            'paid_amount' => '0',
            'items' => [[
                'product' => 'Multiple Status Photos Chair',
                'dimensions' => '100x50x40',
                'qty' => 2,
                'price' => '10000',
                'source' => 'Order',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $order = Order::query()
        ->where('order_id', 'MULTIPLE-STATUS-PHOTOS')
        ->firstOrFail();
    $item = $order->items()->firstOrFail();

    $this->from(route('orders.index'))
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $item->id,
                'status' => 'None',
                'status_images' => [
                    UploadedFile::fake()->create('status-one.jpg', 100, 'image/jpeg'),
                    UploadedFile::fake()->create('status-two.jpg', 100, 'image/jpeg'),
                ],
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $item->refresh();

    expect($item->status_evidence_images)->toHaveCount(2);
    foreach ($item->status_evidence_images as $statusImage) {
        Storage::disk('public')->assertExists($statusImage);
    }
});

test('new products added to an order must be saved as New before mockup upload', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'New Order Item Guest']);
    $order = Order::create([
        'order_id' => 'NEW-ORDER-ITEM',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 10000,
        'status' => 'None',
    ]);
    $existingItem = $order->items()->create([
        'product' => 'Existing Chair',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);

    $this->actingAs($admin)
        ->get(route('orders.edit', $order))
        ->assertSee('name="items[${productIndex}][status]" value="None"', false)
        ->assertDontSee('name="items[${productIndex}][status_images][]"', false);

    $existingProduct = [
        'id' => $existingItem->id,
        'product' => 'Existing Chair',
        'dimensions' => '10x10x10',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'None',
    ];
    $newProduct = [
        'product' => 'New Chair',
        'dimensions' => '10x10x10',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'status_images' => [
            UploadedFile::fake()->create('new-chair-mockup.jpg', 100, 'image/jpeg'),
        ],
    ];
    $payload = [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'paid_amount' => 0,
    ];

    $this->from(route('orders.edit', $order))
        ->put(route('orders.update', $order), $payload + [
            'items' => [$existingProduct, $newProduct],
        ])
        ->assertSessionHasErrors('items.1.status');

    $this->assertDatabaseCount('order_items', 1);

    $newProduct['status'] = 'None';
    unset($newProduct['status_images']);
    $this->put(route('orders.update', $order), $payload + [
        'items' => [$existingProduct, $newProduct],
    ])->assertRedirect(route('orders.index'));

    $savedItem = $order->items()->where('product', 'New Chair')->firstOrFail();
    expect($savedItem->status)->toBe('None')
        ->and($savedItem->factory_status)->toBe('None')
        ->and($savedItem->product_id)->not->toBeNull()
        ->and($savedItem->status_evidence_images)->toBeEmpty();

    $this->assertDatabaseHas('products', [
        'id' => $savedItem->product_id,
        'name' => 'New Chair',
        'dimensions' => '10x10x10',
    ]);
});

test('Factory product queue requires the second work drawing uploaded from order details', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'order_products'],
    ]);
    $guest = Guest::create(['name' => 'Product Progress Guest']);
    $order = Order::create([
        'order_id' => 'PRODUCT-PROGRESS-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Dining Chair',
        'qty' => 10,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertDontSeeText('Dining Chair');

    $this->get(route('orders.show', $order))
        ->assertSeeText('Unggah gambar kedua: gambar kerja PF')
        ->assertSee('name="factory_drawing_image"', false);

    $this->from(route('orders.show', $order))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => UploadedFile::fake()->create('factory-drawing.jpg', 100, 'image/jpeg'),
        ])
        ->assertRedirect(route('orders.show', $order));

    $this->get(route('order-products.index'))
        ->assertSeeText('Dining Chair')
        ->assertSeeText('Lihat gambar kerja')
        ->assertDontSee('id="update-order-product-'.$item->id.'"', false);
});

test('Factory work queue includes production and packing but excludes Bali-only transit items', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $baliAdmin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Shipping Progress Guest']);
    $order = Order::create([
        'order_id' => 'SHIPPING-PROGRESS-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Active Factory Product',
        'qty' => 2,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/active.png',
    ]);
    $order->items()->create([
        'product' => 'Packed Factory Product',
        'qty' => 1,
        'qty_completed' => 1,
        'packed_quantity' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Dalam Perjalanan',
        'factory_status' => 'Packing',
        'factory_drawing_image' => 'orders/factory-drawings/packed.png',
    ]);
    $order->items()->create([
        'product' => 'Bali Transit Product',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Dalam Perjalanan',
        'factory_status' => 'Dalam Perjalanan',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText('Active Factory Product')
        ->assertSeeText('Packed Factory Product')
        ->assertDontSeeText('Bali Transit Product');

    $this->actingAs($baliAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText('Bali Transit Product')
        ->assertSeeText('Packed Factory Product')
        ->assertDontSeeText('Active Factory Product');
});

test('orders become urgent when their delivery deadline is within fourteen days', function () {
    $this->travelTo('2026-03-10 09:00:00');
    $urgentOrder = Order::create([
        'order_id' => 'URGENT-FOURTEEN-DAYS',
        'guest_name' => 'Urgent Guest',
        'total_order' => 0,
        'deadline' => '2026-03-24',
        'status' => 'Proses Produksi',
    ]);
    $notYetUrgentOrder = Order::create([
        'order_id' => 'NOT-YET-URGENT',
        'guest_name' => 'Not Urgent Guest',
        'total_order' => 0,
        'deadline' => '2026-03-25',
        'status' => 'Proses Produksi',
    ]);

    expect($urgentOrder->is_urgent)->toBeTrue()
        ->and($notYetUrgentOrder->is_urgent)->toBeFalse();
});

test('users cannot update products assigned to the other unit', function () {
    $baliAdmin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Cross Unit Product Guest']);
    $order = Order::create([
        'order_id' => 'CROSS-UNIT-PRODUCT-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 10000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Factory Chair',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->actingAs($baliAdmin)
        ->patch(route('order-products.update', $item), [
            'items' => [
                $item->id => [
                    'status' => 'Distribusi',
                    'qty_completed' => 1,
                ],
            ],
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Produksi',
        'qty_completed' => 0,
    ]);
});

test('completed product quantity cannot exceed the order quantity', function () {
    $baliAdmin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Product Quantity Guest']);
    $order = Order::create([
        'order_id' => 'PRODUCT-QUANTITY-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100000,
        'status' => 'Dalam Perjalanan',
    ]);
    $item = $order->items()->create([
        'product' => 'Dining Chair',
        'qty' => 10,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Dalam Perjalanan',
        'factory_status' => 'Packing',
    ]);

    $this->actingAs($baliAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.update', $item), [
            'items' => [
                $item->id => [
                    'status' => 'Dalam Perjalanan',
                    'qty_completed' => 11,
                ],
            ],
        ])
        ->assertSessionHasErrors("items.{$item->id}.qty_completed");

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Dalam Perjalanan',
        'qty_completed' => 0,
    ]);
});

test('quality checks are rejected before a product reaches Bali transit', function () {
    $baliAdmin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Unfinished Shipping Guest']);
    $order = Order::create([
        'order_id' => 'UNFINISHED-SHIPPING-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 120000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Dining Chair',
        'qty' => 12,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($baliAdmin)
        ->from(route('order-products.index'))
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Bagus',
            'quantity' => 1,
        ])
        ->assertSessionHasErrors('result');

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Produksi',
        'quality_good_quantity' => 0,
    ]);
});

test('editing an order cannot reduce product quantity below completed progress', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Edited Quantity Guest']);
    $order = Order::create([
        'order_id' => 'EDITED-QUANTITY-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 100000,
        'deadline' => '2026-03-20',
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Dining Chair',
        'qty' => 10,
        'qty_completed' => 7,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->actingAs($admin)
        ->from(route('orders.edit', $order))
        ->put(route('orders.update', $order), [
            'order_id' => $order->order_id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'deadline' => '2026-03-20',
            'status' => 'Proses Produksi',
            'items' => [[
                'id' => $item->id,
                'product' => 'Dining Chair',
                'qty' => 5,
                'price' => 10000,
                'source' => 'Order',
                'status' => 'Produksi',
            ]],
        ])
        ->assertSessionHasErrors('items.0.qty');

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'qty' => 10,
        'qty_completed' => 7,
    ]);
});

test('order metadata remains editable while a production product is locked', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Locked Product Metadata Guest']);
    $order = Order::create([
        'order_id' => 'LOCKED-PRODUCT-METADATA',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'phone' => '0811111111',
        'note' => 'Catatan lama',
        'total_order' => 10000,
        'deadline' => '2026-03-20',
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Locked Chair',
        'qty' => 1,
        'qty_completed' => 0,
        'price' => 10000,
        'color' => 'Natural',
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($admin)
        ->put(route('orders.update', $order), [
            'order_id' => $order->order_id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'guest_source' => 'Instagram',
            'order_type' => 'Luar Bali',
            'phone' => '0822222222',
            'paid_amount' => 0,
            'deadline' => '2026-04-15 00:00:00',
            'status' => 'Proses Produksi',
            'note' => 'Catatan baru',
            'items' => [[
                'id' => $item->id,
                'product' => 'Locked Chair',
                'qty' => 1,
                'price' => 10000,
                'color' => 'Natural',
                'source' => 'Order',
                'status' => 'Produksi',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'guest_source' => 'Instagram',
        'order_type' => 'Luar Bali',
        'phone' => '0822222222',
        'deadline' => '2026-04-15 00:00:00',
        'note' => 'Catatan baru',
    ]);
    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'product' => 'Locked Chair',
        'qty' => 1,
        'price' => 10000,
        'color' => 'Natural',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);
});

test('completed product progress prevents deleting the item from an order', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Removed Product Guest']);
    $order = Order::create([
        'order_id' => 'REMOVED-PRODUCT-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 100000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Dining Chair',
        'qty' => 10,
        'qty_completed' => 7,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->actingAs($admin)
        ->from(route('orders.edit', $order))
        ->put(route('orders.update', $order), [
            'order_id' => $order->order_id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'status' => 'Proses Produksi',
            'items' => [[
                'product' => 'Replacement Chair',
                'dimensions' => '10x10x10',
                'qty' => 1,
                'price' => 10000,
                'source' => 'Order',
                'status' => 'None',
            ]],
        ])
        ->assertSessionHasErrors('items');

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'product' => 'Dining Chair',
        'qty_completed' => 7,
    ]);
    $this->assertDatabaseCount('order_items', 1);
});

test('unauthenticated users are redirected when updating product progress', function () {
    $this->patch(route('order-products.update', 1))
        ->assertRedirect(route('login'));
});

test('only the oldest unfinished product without progress for seven days receives a warning', function () {
    $this->travelTo('2026-03-01 09:00:00');
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Stagnant Product Guest']);
    $order = Order::create([
        'order_id' => 'STAGNANT-PRODUCT-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 30000,
        'status' => 'Proses Produksi',
    ]);
    $oldestItem = $order->items()->create([
        'product' => 'Stagnant First',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/stagnant-first.png',
    ]);

    $this->travelTo('2026-03-03 09:00:00');
    $secondStagnantItem = $order->items()->create([
        'product' => 'Stagnant Second',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/stagnant-second.png',
    ]);

    $this->travelTo('2026-03-11 09:00:00');
    $recentItem = $order->items()->create([
        'product' => 'Recent Item',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/recent.png',
    ]);

    $response = $this->actingAs($factoryAdmin)->get(route('order-products.index'));

    $response->assertSeeInOrder(['Stagnant First', 'Stagnant Second', 'Recent Item']);
    expect(substr_count($response->getContent(), 'data-stagnant-product-warning'))->toBe(1)
        ->and($oldestItem->fresh()->qty_progress_updated_at->toDateString())->toBe('2026-03-01')
        ->and($secondStagnantItem->fresh()->qty_progress_updated_at->toDateString())->toBe('2026-03-03')
        ->and($recentItem->fresh()->qty_progress_updated_at->toDateString())->toBe('2026-03-11');
});

test('Factory users cannot bypass QC to return a distributed product to production', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Returned Product Guest']);
    $order = Order::create([
        'order_id' => 'RETURNED-PRODUCT-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 10000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Returned Chair',
        'qty' => 1,
        'qty_completed' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Distribusi',
    ]);

    $this->actingAs($factoryAdmin)
        ->patch(route('order-products.update', $item), [
            'items' => [
                $item->id => [
                    'status' => 'Produksi',
                    'qty_completed' => 0,
                    'comment' => 'Ditemukan cacat pada kaki kursi.',
                ],
            ],
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Distribusi',
        'qty_completed' => 1,
    ]);
    $this->assertDatabaseMissing('order_events', [
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'type' => 'item_rework_requested',
    ]);
});

test('Factory users cannot change production status from the order detail', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Order Detail Rework Guest']);
    $order = Order::create([
        'order_id' => 'ORDER-DETAIL-REWORK',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 10000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Rework Detail Chair',
        'qty' => 1,
        'qty_completed' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Distribusi',
    ]);

    $this->actingAs($factoryAdmin)
        ->patch(route('orders.production.update', $order), [
            'items' => [
                ['id' => $item->id, 'status' => 'Produksi'],
            ],
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Distribusi',
        'qty_completed' => 1,
    ]);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Proses Produksi']);
});

test('editing a distributed order item is rejected without changing its production state', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Order Edit Rework Guest']);
    $order = Order::create([
        'order_id' => 'ORDER-EDIT-REWORK',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 10000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Rework Edit Chair',
        'qty' => 1,
        'qty_completed' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'Distribusi',
    ]);
    $orderData = [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'paid_amount' => 0,
        'status' => 'None',
        'items' => [[
            'id' => $item->id,
            'product' => $item->product,
            'qty' => 1,
            'price' => 10000,
            'source' => 'Order',
            'status' => 'Produksi',
            'status_image' => UploadedFile::fake()->create('production.jpg', 100, 'image/jpeg'),
        ]],
    ];

    $this->actingAs($admin)
        ->from(route('orders.edit', $order))
        ->put(route('orders.update', $order), $orderData)
        ->assertSessionHasErrors('items.0.status');

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Distribusi',
        'qty_completed' => 1,
    ]);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'None']);
});
