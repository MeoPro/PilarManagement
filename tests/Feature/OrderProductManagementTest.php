<?php

use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderItemService;
use App\Models\Subcontractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function stockProductImage(string $filename): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $filename,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC'),
    );
}

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
            'factory_sale_price' => '10,000',
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
        ->and($savedItem->dimensions)->toBe('10x10x10')
        ->and($savedItem->product_id)->not->toBeNull()
        ->and($savedItem->status_evidence_images)->toBeEmpty();

    $this->get(route('orders.edit', $order))
        ->assertSee('value="10x10x10"', false);

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
        ->assertSeeText('Dining Chair')
        ->assertSeeText('New')
        ->assertSeeText('Unggah gambar kerja tukang');

    $this->get(route('orders.show', $order))
        ->assertSeeText('New')
        ->assertSeeText('Unggah gambar kerja tukang PF')
        ->assertSee('name="factory_drawing_image"', false)
        ->assertSee('name="factory_sale_price"', false);

    $this->from(route('orders.show', $order))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => UploadedFile::fake()->create('factory-drawing.jpg', 100, 'image/jpeg'),
            'factory_sale_price' => '10,000',
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

test('Stock products bypass Factory and enter the PB queue for readiness checks', function () {
    $salesAdmin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();

    $this->actingAs($salesAdmin)
        ->post(route('orders.store'), [
            'order_id' => 'STOCK-DIRECT-TO-PB',
            'guest_name' => 'Stock Guest',
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'status' => 'None',
            'items' => [[
                'product' => 'Stock Dining Chair',
                'dimensions' => '10x10x10',
                'qty' => 2,
                'price' => 10000,
                'source' => 'Stock',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $order = Order::query()
        ->where('order_id', 'STOCK-DIRECT-TO-PB')
        ->firstOrFail();
    $item = $order->items()->firstOrFail();

    expect($item->status)->toBe('Distribusi')
        ->and($item->factory_status)->toBe('None');

    $this->get(route('order-products.index'))
        ->assertSeeText('Stock Dining Chair')
        ->assertSee('data-stock-quality-result="Bagus"', false)
        ->assertSee('data-stock-quality-result="Rusak"', false)
        ->assertSee('data-stock-quality-result="Kurang"', false)
        ->assertSee('data-stock-quality-dialog', false)
        ->assertSee('data-stock-quality-severity', false)
        ->assertSeeText('Semua')
        ->assertDontSee('name="items['.$item->id.'][status]"', false)
        ->assertDontSee('name="items['.$item->id.'][status_images][]"', false)
        ->assertDontSee('id="update-order-product-'.$item->id.'"', false)
        ->assertDontSeeText('Subkontraktor');

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertDontSeeText('Stock Dining Chair');

    $this->actingAs($salesAdmin)
        ->patch(route('order-products.update', $item), [
            'items' => [
                $item->id => [
                    'status' => 'Ready',
                    'qty_completed' => 0,
                ],
            ],
        ])
        ->assertForbidden();

    $this->from(route('orders.edit', $order))
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $item->id,
                'status' => 'Produksi',
                'status_images' => [stockProductImage('stock-mockup.png')],
            ]],
        ])
        ->assertSessionHasErrors('items.0.status');

    expect($item->fresh()->status)->toBe('Distribusi')
        ->and($item->fresh()->factory_status)->toBe('None');

    $this->post(route('order-products.quality.store', $item), [
        'result' => 'Bagus',
        'quantity' => 2,
    ])->assertRedirect();

    expect($item->fresh()->status)->toBe('Ready')
        ->and($item->fresh()->factory_status)->toBe('Ready')
        ->and((int) $item->fresh()->quality_good_quantity)->toBe(2);
});

test('Stock products marked short become ready without entering Factory', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::create([
        'order_id' => 'STOCK-SHORT-TO-READY',
        'guest_name' => 'Stock Short Guest',
        'total_order' => 20000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Stock Short Chair',
        'qty' => 2,
        'price' => 10000,
        'source' => 'Stock',
        'status' => 'Distribusi',
        'factory_status' => 'None',
    ]);

    $this->actingAs($admin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Kurang',
            'quantity' => 2,
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Ready')
        ->and($item->fresh()->factory_status)->toBe('Ready')
        ->and((int) $item->fresh()->quality_short_quantity)->toBe(2);
});

test('Stock products marked damaged enter Service before the Factory queue', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'order_products', 'attendance', 'subcontractors'],
    ]);
    $order = Order::create([
        'order_id' => 'STOCK-DAMAGED-TO-PF',
        'guest_name' => 'Stock Damaged Guest',
        'total_order' => 20000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Stock Damaged Chair',
        'qty' => 2,
        'price' => 10000,
        'source' => 'Stock',
        'status' => 'Distribusi',
        'factory_status' => 'None',
    ]);

    $this->actingAs($admin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Rusak',
            'quantity' => 2,
        ])
        ->assertSessionHasErrors('severity');

    expect((int) $item->fresh()->quality_damaged_quantity)->toBe(0)
        ->and((int) $item->fresh()->service_quantity)->toBe(0);

    $this->actingAs($admin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Rusak',
            'quantity' => 2,
            'severity' => 'Ringan',
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Distribusi')
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and((int) $item->fresh()->quality_damaged_quantity)->toBe(0)
        ->and((int) $item->fresh()->service_quantity)->toBe(2);

    $service = OrderItemService::query()->where('order_item_id', $item->id)->firstOrFail();
    $this->assertDatabaseHas('order_item_services', [
        'id' => $service->id,
        'quantity' => 2,
        'status' => 'Service',
        'severity' => 'Ringan',
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('order-item-services.result.store', $service), [
            'result' => 'Bagus',
            'quantity' => 1,
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->from(route('order-products.index'))
        ->post(route('order-item-services.result.store', $service), [
            'result' => 'Rusak',
            'quantity' => 3,
        ])
        ->assertSessionHasErrors('quantity');

    expect($service->fresh()->status)->toBe('Service')
        ->and((int) $service->fresh()->quantity)->toBe(2);

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertDontSeeText('Stock Damaged Chair');

    $this->actingAs($admin)
        ->get(route('order-products.index'))
        ->assertSeeText('Service Ringan · 2 unit')
        ->assertSeeText('Bagus')
        ->assertSeeText('Rusak');

    $this->post(route('order-item-services.result.store', $service), [
        'result' => 'Rusak',
        'quantity' => 1,
    ])->assertRedirect();

    expect($item->fresh()->status)->toBe('Produksi')
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and((int) $item->fresh()->quality_damaged_quantity)->toBe(1)
        ->and((int) $item->fresh()->service_quantity)->toBe(1)
        ->and($service->fresh()->status)->toBe('Produksi')
        ->and($service->fresh()->severity)->toBe('Parah');

    $pendingService = OrderItemService::query()
        ->where('order_item_id', $item->id)
        ->where('status', 'Service')
        ->firstOrFail();
    expect($pendingService->severity)->toBe('Ringan');

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText('Stock Damaged Chair')
        ->assertSeeText('New')
        ->assertSeeText('Unggah gambar kerja tukang');

    $this->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => stockProductImage('stock-factory-drawing.png'),
        ])
        ->assertRedirect(route('order-products.index'));

    $subcontractor = Subcontractor::factory()->create();
    $this->post(route('order-products.subcontractor-assignments.store', $item), [
        'subcontractor_id' => $subcontractor->id,
        'quantity' => 1,
    ])->assertSessionHasErrors('subcontractor_id');

    $this->actingAs($admin)
        ->post(route('order-item-services.result.store', $pendingService), [
            'result' => 'Bagus',
            'quantity' => 1,
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Produksi')
        ->and((int) $item->fresh()->quality_good_quantity)->toBe(1)
        ->and((int) $item->fresh()->service_quantity)->toBe(0);

    $this->actingAs($factoryAdmin)
        ->post(route('order-products.subcontractor-assignments.store', $item), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 1,
        ])
        ->assertRedirect();

    expect($item->activeSubcontractorAssignments()->sum('assigned_quantity'))->toBe(1);

    $this->actingAs($admin)
        ->get(route('orders.show', $order))
        ->assertSeeText('Selesai (1 unit)')
        ->assertSeeText('Produksi (1 unit)')
        ->assertSeeText('Service (0 unit)');

    expect($item->fresh()->factory_status)->toBe('Produksi')
        ->and($item->fresh()->factory_drawing_image)->not->toBeNull();
});

test('severe Stock damage enters Factory production without local Service', function () {
    $admin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'order_products'],
    ]);
    $order = Order::create([
        'order_id' => 'STOCK-SEVERE-DAMAGE',
        'guest_name' => 'Severe Stock Guest',
        'total_order' => 20000,
        'status' => 'Distribusi',
    ]);
    $item = $order->items()->create([
        'product' => 'Severely Damaged Chair',
        'qty' => 2,
        'price' => 10000,
        'source' => 'Stock',
        'status' => 'Distribusi',
        'factory_status' => 'None',
    ]);

    $this->actingAs($admin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Rusak',
            'quantity' => 1,
            'severity' => 'Parah',
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Produksi')
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and((int) $item->fresh()->quality_damaged_quantity)->toBe(1)
        ->and((int) $item->fresh()->service_quantity)->toBe(0)
        ->and($order->fresh()->status)->toBe('Proses Produksi');

    $this->assertDatabaseHas('order_item_services', [
        'order_item_id' => $item->id,
        'quantity' => 1,
        'status' => 'Produksi',
        'severity' => 'Parah',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText('Severely Damaged Chair')
        ->assertSeeText('New')
        ->assertSeeText('Unggah gambar kerja tukang');
});

test('partial Stock QC keeps Service units separate until the service result is recorded', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'order_products', 'attendance', 'subcontractors'],
    ]);
    $order = Order::create([
        'order_id' => 'STOCK-PARTIAL-QC',
        'guest_name' => 'Stock Partial Guest',
        'total_order' => 20000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Stock Partial Chair',
        'qty' => 2,
        'price' => 10000,
        'source' => 'Stock',
        'status' => 'Distribusi',
        'factory_status' => 'None',
    ]);

    $this->actingAs($admin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Rusak',
            'quantity' => 3,
            'severity' => 'Ringan',
        ])
        ->assertSessionHasErrors('quantity');

    expect((int) $item->fresh()->quality_damaged_quantity)->toBe(0);

    $this->actingAs($admin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Rusak',
            'quantity' => 1,
            'severity' => 'Ringan',
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Distribusi')
        ->and((int) $item->fresh()->quality_damaged_quantity)->toBe(0)
        ->and((int) $item->fresh()->service_quantity)->toBe(1)
        ->and($item->fresh()->quality_unchecked_quantity)->toBe(1);

    $this->get(route('order-products.index'))
        ->assertSeeText('Stock Partial Chair')
        ->assertSee('data-stock-quality-max="1"', false)
        ->assertSeeText('Service Ringan · 1 unit');

    $this->actingAs($admin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Bagus',
            'quantity' => 1,
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Distribusi')
        ->and((int) $item->fresh()->quality_good_quantity)->toBe(1)
        ->and((int) $item->fresh()->quality_damaged_quantity)->toBe(0)
        ->and((int) $item->fresh()->service_quantity)->toBe(1)
        ->and($item->fresh()->quality_unchecked_quantity)->toBe(0);

    $service = OrderItemService::query()->where('order_item_id', $item->id)->firstOrFail();
    $this->actingAs($admin)
        ->post(route('order-item-services.result.store', $service), [
            'result' => 'Bagus',
            'quantity' => 1,
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Ready')
        ->and($item->fresh()->factory_status)->toBe('Ready')
        ->and((int) $item->fresh()->quality_good_quantity)->toBe(2)
        ->and((int) $item->fresh()->service_quantity)->toBe(0);

    $this->get(route('orders.show', $order))
        ->assertSeeText('Selesai (2 unit)')
        ->assertSeeText('Riwayat Service')
        ->assertSeeText('Selesai:')
        ->assertSeeText('1 unit');
});

test('orders cannot remove products that have Service history', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::create([
        'order_id' => 'STOCK-SERVICE-EDIT-LOCK',
        'guest_name' => 'Service Edit Guest',
        'total_order' => 10000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Service Locked Chair',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Stock',
        'status' => 'Distribusi',
    ]);
    $item->services()->create([
        'quantity' => 1,
        'status' => 'Service',
    ]);

    $this->actingAs($admin)
        ->put(route('orders.update', $order), [
            'order_id' => $order->order_id,
            'guest_name' => $order->guest_name,
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'paid_amount' => 0,
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
        'product' => 'Service Locked Chair',
    ]);
});

test('Stock products cannot be assigned to a subcontractor before entering PF work', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $subcontractor = Subcontractor::factory()->create();
    $order = Order::create([
        'order_id' => 'STOCK-NO-SUBCON-BEFORE-PF',
        'guest_name' => 'Stock Assignment Guest',
        'total_order' => 10000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Stock Assignment Chair',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Stock',
        'status' => 'Distribusi',
        'factory_status' => 'None',
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('order-products.subcontractor-assignments.store', $item), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 1,
        ])
        ->assertSessionHasErrors('subcontractor_id');

    expect($item->subcontractorAssignments()->exists())->toBeFalse();
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

test('orders without activity for seven days become urgent and move above active orders', function () {
    $this->travelTo('2026-03-10 09:00:00');
    $admin = User::factory()->admin()->create();
    $staleOrder = Order::create([
        'order_id' => 'STALE-ORDER',
        'guest_name' => 'Stale Guest',
        'total_order' => 100,
        'deadline' => today()->addDays(30),
        'status' => 'Proses Produksi',
    ]);
    $item = $staleOrder->items()->create([
        'product' => 'Stale Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);
    $staleOrder->forceFill([
        'last_edit' => now()->subDays(8),
        'created_at' => now()->subDays(30),
        'updated_at' => now()->subDays(8),
    ])->saveQuietly();
    $activeOrder = Order::create([
        'order_id' => 'ACTIVE-ORDER',
        'guest_name' => 'Active Guest',
        'total_order' => 100,
        'deadline' => today()->addDay(),
        'status' => 'Proses Produksi',
    ]);

    $response = $this->actingAs($admin)
        ->get(route('orders.index', ['search' => 'ORDER']))
        ->assertSee('STALE-ORDER')
        ->assertSee('ACTIVE-ORDER')
        ->assertSee('URGENT');
    $responseContent = $response->getContent();

    expect($staleOrder->fresh()->is_urgent)->toBeTrue()
        ->and(strpos($responseContent, 'SPK STALE-ORDER'))
        ->toBeLessThan(strpos($responseContent, 'SPK ACTIVE-ORDER'));

    $item->update(['color' => 'Oak']);

    expect($staleOrder->fresh()->is_urgent)->toBeFalse();

    $staleOrder->forceFill([
        'last_edit' => now()->subDays(8),
        'updated_at' => now()->subDays(8),
    ])->saveQuietly();
    OrderComment::create([
        'order_id' => $staleOrder->id,
        'user_id' => $admin->id,
        'business_unit' => 'bali',
        'body' => 'Ada pembaruan order.',
    ]);

    expect($staleOrder->fresh()->is_urgent)->toBeFalse();
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
    $deadline = today()->addDays(20)->toDateString();
    $order = Order::create([
        'order_id' => 'EDITED-QUANTITY-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 100000,
        'deadline' => $deadline,
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
            'deadline' => $deadline,
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
    $deadline = today()->addDays(20)->toDateString();
    $order = Order::create([
        'order_id' => 'LOCKED-PRODUCT-METADATA',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'phone' => '0811111111',
        'note' => 'Catatan lama',
        'total_order' => 10000,
        'deadline' => $deadline,
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
            'deadline' => $deadline,
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
        'deadline' => $deadline.' 00:00:00',
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
