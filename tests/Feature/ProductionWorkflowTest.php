<?php

use App\Models\Employee;
use App\Models\FinishingAssignment;
use App\Models\Guest;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function workflowImage(string $filename): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $filename,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC'),
    );
}

function createWorkflowOrder(string $orderId, array $itemAttributes = []): array
{
    $guest = Guest::create(['name' => "{$orderId} Guest"]);
    $order = Order::create([
        'order_id' => $orderId,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create(array_merge([
        'product' => 'Workflow Chair',
        'qty' => 2,
        'qty_completed' => 0,
        'price' => 500,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ], $itemAttributes));

    return [$order, $item];
}

test('PF work stays new until every production item has a work drawing', function () {
    Storage::fake('public');
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['orders', 'order_products', 'attendance'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-DRAWING', [
        'status' => 'None',
        'factory_status' => 'None',
    ]);
    $order->update(['status' => 'None']);
    $pendingItem = $order->items()->create([
        'product' => 'Pending New Table',
        'qty' => 1,
        'price' => 500,
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);

    $this->actingAs($salesAdmin)
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $item->id,
                'status' => 'None',
                'status_images' => [workflowImage('mockup.png')],
            ]],
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Produksi')
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and($order->fresh()->status)->toBe('None');

    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText($item->product)
        ->assertSeeText('New')
        ->assertSeeText('Unggah gambar kerja tukang')
        ->assertDontSeeText($pendingItem->product);
    $this->get(route('orders.index'))
        ->assertSeeText($order->order_id)
        ->assertSeeText($item->product)
        ->assertDontSeeText($pendingItem->product)
        ->assertDontSee('Simpan Status');
    $this->get(route('orders.show', $order))
        ->assertDontSee('Perbarui status order')
        ->assertDontSee('Simpan Status')
        ->assertSeeText('Unggah gambar kerja PF')
        ->assertDontSeeText($pendingItem->product);

    $this->actingAs($factoryAdmin)
        ->post(route('order-items.piecework-assignments.store', $item), [
            'employee_id' => $employee->id,
            'target_quantity' => 1,
            'unit_price' => '25,000',
            'target_deadline' => now()->addDay()->toDateString(),
        ])
        ->assertSessionHasErrors('target_quantity');

    $this->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('drawing-one.png'),
            'factory_sale_price' => '100,000',
        ])->assertRedirect(route('order-products.index'));

    expect($order->fresh()->status)->toBe('None');
    Storage::disk('public')->assertExists($item->fresh()->factory_drawing_image);
    expect($item->fresh()->factory_status)->toBe('Siap Produksi');
    $this->get(route('order-products.index'))
        ->assertSeeText($item->product);

    $this->actingAs($salesAdmin)
        ->from(route('orders.edit', $order))
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $pendingItem->id,
                'status' => 'None',
                'status_images' => [workflowImage('pending-table-mockup.png')],
            ]],
        ])
        ->assertRedirect(route('orders.edit', $order));

    expect($order->fresh()->status)->toBe('Proses Produksi')
        ->and($pendingItem->fresh()->factory_status)->toBe('None');

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText($pendingItem->product)
        ->assertSeeText('Unggah gambar kerja tukang');

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $pendingItem), [
            'factory_drawing_image' => workflowImage('drawing-two.png'),
            'factory_sale_price' => '100,000',
        ])
        ->assertRedirect(route('order-products.index'));

    expect($pendingItem->fresh()->factory_drawing_image)->not->toBeNull()
        ->and($pendingItem->fresh()->factory_status)->toBe('Siap Produksi')
        ->and($order->fresh()->status)->toBe('Proses Produksi');
    Storage::disk('public')->assertExists($pendingItem->fresh()->factory_drawing_image);
});

test('PF order list counts down to its internal deadline and waits for every work drawing', function () {
    Storage::fake('public');
    $this->travelTo('2026-11-18 10:00:00');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'order_products'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-LIST-START', [
        'factory_status' => 'None',
    ]);
    $order->refresh()->update(['deadline' => today()->addDays(9)->toDateString()]);
    $pendingItem = $order->items()->create([
        'product' => 'Workflow Table',
        'qty' => 1,
        'price' => 500,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'None',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.index', ['search' => $order->order_id]))
        ->assertSeeText('New')
        ->assertDontSeeText('Proses Produksi')
        ->assertSee('20/11/2026')
        ->assertSeeText('1 hari')
        ->assertDontSee('Target PF:', false)
        ->assertDontSeeText('Sisa:')
        ->assertDontSee('Target kirim PB:', false);

    $this->patch(route('order-products.factory-drawing.update', $item), [
        'factory_drawing_image' => workflowImage('list-start-drawing.png'),
        'factory_sale_price' => '100,000',
    ])->assertRedirect();

    expect($item->fresh()->factory_status)->toBe('Siap Produksi')
        ->and((float) $item->fresh()->factory_sale_price)->toBe(100000.0);

    $this->get(route('orders.index', ['search' => $order->order_id]))
        ->assertSeeText('New')
        ->assertDontSeeText('Proses Produksi');

    $this->patch(route('order-products.factory-drawing.update', $pendingItem), [
        'factory_drawing_image' => workflowImage('list-start-table-drawing.png'),
        'factory_sale_price' => '100,000',
    ])->assertRedirect();

    $this->get(route('orders.index', ['search' => $order->order_id]))
        ->assertSeeText('Proses Produksi');
});

test('PF order list shows revenue only for products already handed over to PB', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders'],
    ]);
    [$order, $handedOverItem] = createWorkflowOrder('WORKFLOW-LIST-REVENUE', [
        'qty' => 2,
        'factory_status' => 'Distribusi',
        'status' => 'Distribusi',
        'factory_sale_price' => 125000,
        'factory_handover_at' => now(),
    ]);
    $order->items()->create([
        'product' => 'Not Handed Over Table',
        'qty' => 1,
        'price' => 500,
        'factory_sale_price' => 90000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.index', ['search' => $order->order_id]))
        ->assertSeeText('Total Pendapatan PF ke PB')
        ->assertSeeText('Omzet PF ke PB')
        ->assertSeeText('Rp 250.000')
        ->assertDontSeeText('Rp 340.000');
});

test('PB order details render as a customer-focused printable receipt', function () {
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['orders'],
    ]);
    [$order] = createWorkflowOrder('WORKFLOW-PB-ORDER-INFORMATION');
    $order->update([
        'is_urgent' => true,
        'guest_source' => 'Instagram',
        'order_type' => 'Luar Bali',
        'phone' => '081234567890',
        'deadline' => '2026-11-30',
    ]);

    $this->actingAs($salesAdmin)
        ->get(route('orders.show', $order))
        ->assertSeeInOrder([
            '<h2>Informasi Pelanggan</h2>',
            '<h2>Rincian Pesanan</h2>',
        ], false)
        ->assertSee('class="order-customer-receipt"', false)
        ->assertSee('aria-label="Edit order WORKFLOW-PB-ORDER-INFORMATION"', false)
        ->assertSeeText('Cetak nota')
        ->assertSeeText('WORKFLOW-PB-ORDER-INFORMATION Guest')
        ->assertSeeText('Luar Bali')
        ->assertSeeText('081234567890')
        ->assertDontSeeText('Instagram')
        ->assertDontSee('status-badge', false)
        ->assertDontSeeText('URGENT');
});

test('PF order details hide all guest information and PB-only payment controls', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders'],
    ]);
    $guest = Guest::create([
        'name' => 'Factory Order Guest',
        'address' => 'Jalan Merdeka 10',
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-ORDER-INFORMATION', [
        'factory_status' => 'None',
    ]);
    $order->update([
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Instagram',
        'order_type' => 'Luar Bali',
        'phone' => '081234567890',
        'note' => 'Gunakan kemasan khusus.',
        'deadline' => '2026-11-30',
    ]);
    $item->update([
        'price' => 123456,
        'factory_sale_price' => 60000,
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSeeInOrder([
            '<h2>Informasi Order</h2>',
            '<h2>List Produk</h2>',
        ], false)
        ->assertSeeText('Target PF')
        ->assertDontSeeText('Informasi Tamu')
        ->assertDontSeeText('Instagram')
        ->assertDontSeeText('Luar Bali')
        ->assertDontSeeText('Factory Order Guest')
        ->assertDontSeeText('081234567890')
        ->assertDontSeeText('Jalan Merdeka 10')
        ->assertDontSeeText('Gunakan kemasan khusus.')
        ->assertDontSeeText('Edit Order')
        ->assertDontSeeText('Riwayat cicilan')
        ->assertSeeText('Rp 60.000')
        ->assertDontSeeText('Harga PB /pcs')
        ->assertDontSeeText('Rp 123.456');

    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)
        ->withSession(['active_business_unit' => User::UNIT_FACTORY])
        ->get(route('orders.show', $order))
        ->assertSeeText('Harga PB /pcs')
        ->assertSeeText('Rp 123.456')
        ->assertSeeText('Harga PF /pcs')
        ->assertSeeText('Rp 60.000');
});

test('PF cannot upload a work drawing before PB starts production', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-DRAWING-TOO-EARLY', [
        'status' => 'None',
        'factory_status' => 'None',
    ]);
    $order->update(['status' => 'None']);

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('too-early.png'),
            'factory_sale_price' => '100,000',
        ])
        ->assertSessionHasErrors('factory_drawing_image');

    expect($item->fresh()->factory_drawing_image)->toBeNull()
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and($order->fresh()->status)->toBe('None');
    $this->assertDatabaseCount('order_events', 0);
});

test('PF handover records order value but does not recognize revenue before PB accepts products', function () {
    Storage::fake('public');
    $this->travelTo('2026-11-18 10:00:00');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-PF-TRANSFER');

    $this->actingAs($factoryAdmin)
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('transfer-drawing.png'),
            'factory_sale_price' => '125,000',
        ])
        ->assertRedirect();

    expect((float) $item->fresh()->factory_sale_price)->toBe(125000.0);

    $item->update([
        'status' => 'Dalam Perjalanan',
        'factory_status' => 'Proses Kirim',
        'qty_completed' => 1,
    ]);

    $this->actingAs($salesAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.update', $item), [
            'items' => [
                $item->id => [
                    'status' => 'Distribusi',
                    'status_images' => [workflowImage('partial-handover.png')],
                ],
            ],
        ])
        ->assertSessionHasErrors("items.{$item->id}.status");

    expect($item->fresh()->factory_handover_at)->toBeNull();

    $item->update(['qty_completed' => 2]);

    $this->actingAs($salesAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.update', $item), [
            'items' => [
                $item->id => [
                    'status' => 'Distribusi',
                    'status_images' => [workflowImage('complete-handover.png')],
                ],
            ],
        ])
        ->assertRedirect(route('order-products.index'));

    expect($item->fresh()->factory_handover_at)->not->toBeNull();

    $factoryRevenueAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['revenue'],
    ]);
    $this->actingAs($factoryRevenueAdmin)
        ->get(route('revenue.index', [
            'from' => '2026-11-01',
            'to' => '2026-11-18',
        ]))
        ->assertSeeText('Nilai order PF ke PB')
        ->assertDontSeeText('Penjualan PF ke PB')
        ->assertSeeText($order->order_id)
        ->assertViewHas('factoryOrderValueTotal', 250000.0)
        ->assertViewHas('factorySaleTotal', 0.0)
        ->assertViewHas('totalIncoming', 0.0)
        ->assertViewHas('factoryNetRevenue', 0.0);
});

test('PF keeps new status after saving only a work drawing until the sale price is saved', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-DRAWING-BEFORE-PRICE', [
        'factory_status' => 'None',
    ]);

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('drawing-before-price.png'),
        ])
        ->assertRedirect(route('order-products.index'));

    expect($item->fresh()->factory_drawing_image)->not->toBeNull()
        ->and($item->fresh()->factory_sale_price)->toBeNull()
        ->and($item->fresh()->factory_status)->toBe('None');
    Storage::disk('public')->assertExists($item->fresh()->factory_drawing_image);

    $this->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_sale_price' => '150,000',
        ])
        ->assertRedirect(route('order-products.index'));

    expect((float) $item->fresh()->factory_sale_price)->toBe(150000.0)
        ->and($item->fresh()->factory_status)->toBe('Siap Produksi');
});

test('PF image updates and deletion return JSON without requiring a page redirect', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $product = Product::factory()->create();
    [$order, $item] = createWorkflowOrder('WORKFLOW-AJAX-FACTORY-IMAGE', [
        'product_id' => $product->id,
        'factory_status' => 'None',
    ]);

    $this->actingAs($factoryAdmin)
        ->withHeader('Accept', 'application/json')
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('ajax-factory-image.png'),
            'factory_sale_price' => '125,000',
        ])
        ->assertOk()
        ->assertJsonPath('item_id', $item->id)
        ->assertJsonPath('factory_status_label', 'Siap Produksi')
        ->assertJsonPath('factory_sale_price_label', 'Rp 125.000');

    $imagePath = $item->fresh()->factory_drawing_image;
    Storage::disk('public')->assertExists($imagePath);

    $this->actingAs($factoryAdmin)
        ->deleteJson(route('order-products.images.destroy', [$item, 'pf', 0]))
        ->assertOk()
        ->assertJsonPath('factory_drawing_url', null)
        ->assertJsonPath('factory_status_label', 'New');

    expect($item->fresh()->factory_drawing_image)->toBeNull()
        ->and($product->fresh()->factory_mockup)->toBeNull();
    Storage::disk('public')->assertMissing($imagePath);
});

test('PF stock price updates synchronize the catalog and return the saved total', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $product = Product::factory()->create(['factory_sale_price' => 10000]);
    [, $item] = createWorkflowOrder('WORKFLOW-STOCK-PRICE', [
        'product_id' => $product->id,
        'product' => $product->name,
        'qty' => 3,
        'stock_factory_quantity' => 2,
        'source' => 'Stock',
        'status' => 'Distribusi',
        'factory_status' => 'None',
        'factory_sale_price' => 10000,
        'factory_drawing_image' => 'orders/factory-drawings/stock-price.png',
    ]);

    $this->actingAs($factoryAdmin)
        ->patchJson(route('order-products.factory-drawing.update', $item), [
            'factory_sale_price' => '30,000',
        ])
        ->assertOk()
        ->assertJsonPath('factory_sale_price_label', 'Rp 30.000')
        ->assertJsonPath('factory_total_price_label', 'Rp 60.000');

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'factory_sale_price' => 30000,
    ]);
    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'factory_sale_price' => 30000,
    ]);
});

test('PF can replace an existing work drawing while finishing before handover', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $product = Product::factory()->create();
    $oldDrawing = 'orders/factory-drawings/old-finishing-drawing.png';
    Storage::disk('public')->put($oldDrawing, 'old drawing');
    [, $item] = createWorkflowOrder('WORKFLOW-REPLACE-FINISHING-DRAWING', [
        'product_id' => $product->id,
        'factory_status' => 'Finishing',
        'status' => 'Dalam Perjalanan',
        'factory_sale_price' => 70000,
        'factory_drawing_image' => $oldDrawing,
    ]);

    $this->actingAs($factoryAdmin)
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('new-finishing-drawing.png'),
        ])
        ->assertRedirect();

    $newDrawing = $item->fresh()->factory_drawing_image;
    expect($newDrawing)->not->toBe($oldDrawing)
        ->and($item->fresh()->factory_status)->toBe('Finishing')
        ->and($item->fresh()->factory_handover_at)->toBeNull();
    Storage::disk('public')->assertExists($newDrawing);
    Storage::disk('public')->assertMissing($oldDrawing);
});

test('factory order detail reveals each later stage after the prior stage has progress', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    [$order, $item] = createWorkflowOrder('WORKFLOW-STAGE-PROGRESS', [
        'qty' => 100,
        'qty_completed' => 0,
        'factory_status' => 'Siap Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/stage-progress.png',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSee('data-factory-stage="production"', false)
        ->assertSee('0/100')
        ->assertDontSee('data-factory-stage="finishing"', false)
        ->assertDontSee('data-factory-stage="shipping"', false);

    $item->update([
        'qty_completed' => 20,
        'factory_status' => 'Produksi',
    ]);

    $this->get(route('orders.show', $order))
        ->assertSee('data-factory-stage="production"', false)
        ->assertSee('20/100')
        ->assertSee('data-factory-stage="finishing"', false)
        ->assertSee('0/20')
        ->assertDontSee('data-factory-stage="shipping"', false);

    $item->update([
        'packed_quantity' => 5,
        'factory_status' => 'Proses Kirim',
        'status' => 'Dalam Perjalanan',
    ]);

    $this->get(route('orders.show', $order))
        ->assertSee('data-factory-stage="finishing"', false)
        ->assertSee('5/20')
        ->assertSee('data-factory-stage="shipping"', false)
        ->assertSee('0/5');

    $salesAdmin = User::factory()->admin()->create();
    $this->actingAs($salesAdmin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Bagus',
            'quantity' => 5,
        ])
        ->assertRedirect();

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSee('data-factory-stage-count="finishing">5/20', false)
        ->assertSee('data-factory-stage-count="shipping">5/5', false);

    $this->actingAs($salesAdmin)
        ->get(route('orders.show', $order))
        ->assertDontSeeText('5/100 Selesai');
    $this->get(route('orders.edit', $order))
        ->assertSeeText('Sudah diterima bagus: 5 unit')
        ->assertSeeText('Belum diterima bagus: 95 unit');
});

test('PF uploads update the product catalog mockup without replacing PB mockups', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $product = Product::factory()->create([
        'name' => 'Workflow Chair',
        'mockup' => 'catalog/mockups/pb-chair.png',
        'mockups' => ['catalog/mockups/pb-chair.png'],
        'factory_mockup' => 'catalog/mockups/pf-chair-old.png',
    ]);
    Storage::disk('public')->put('catalog/mockups/pb-chair.png', 'PB mockup');
    Storage::disk('public')->put('catalog/mockups/pf-chair-old.png', 'Old PF mockup');
    [$order, $item] = createWorkflowOrder('WORKFLOW-CATALOG-MOCKUP', [
        'product_id' => $product->id,
    ]);

    $this->actingAs($factoryAdmin)
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('pf-chair.png'),
            'factory_sale_price' => '125,000',
        ])
        ->assertRedirect();

    $catalogProduct = $product->fresh();
    expect($catalogProduct->factory_mockup)->toStartWith('catalog/mockups/')
        ->and($catalogProduct->mockupPaths())->toBe(['catalog/mockups/pb-chair.png'])
        ->and($item->fresh()->factory_drawing_image)->toStartWith('orders/factory-drawings/');
    Storage::disk('public')->assertExists($catalogProduct->factory_mockup);
    Storage::disk('public')->assertMissing('catalog/mockups/pf-chair-old.png');
    Storage::disk('public')->assertExists($item->fresh()->factory_drawing_image);

    $this->actingAs(User::factory()->admin()->create([
        'accessible_menus' => ['products'],
    ]))
        ->get(route('products.show', $catalogProduct))
        ->assertSeeText('Mockup PF (gambar kedua)')
        ->assertSee(asset('storage/'.$catalogProduct->factory_mockup), false);

    $this->actingAs($factoryAdmin)
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_sale_price' => '100,000',
        ])->assertRedirect();

    expect((float) $item->fresh()->factory_sale_price)->toBe(100000.0)
        ->and((float) $product->fresh()->factory_sale_price)->toBe(100000.0);

    $this->patch(route('order-products.factory-drawing.update', $item), [
        'factory_sale_price' => '150,000',
    ])->assertRedirect();

    expect((float) $item->fresh()->factory_sale_price)->toBe(150000.0)
        ->and((float) $product->fresh()->factory_sale_price)->toBe(150000.0);
});

test('new orders reuse both catalog mockups and the saved PF price', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $pbMockupPaths = [
        'catalog/mockups/pb-chair-front.png',
        'catalog/mockups/pb-chair-side.png',
    ];
    $pfMockupPath = 'catalog/mockups/pf-chair.png';
    foreach ([...$pbMockupPaths, $pfMockupPath] as $mockupPath) {
        Storage::disk('public')->put($mockupPath, 'catalog image');
    }

    $product = Product::factory()->create([
        'name' => 'Catalog Reuse Chair',
        'mockup' => $pbMockupPaths[0],
        'mockups' => $pbMockupPaths,
        'factory_mockup' => $pfMockupPath,
        'factory_sale_price' => 125000,
    ]);

    $this->actingAs($admin)
        ->post(route('orders.store'), [
            'order_id' => 'WORKFLOW-CATALOG-REUSE',
            'guest_name' => 'Catalog Reuse Guest',
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'items' => [[
                'product' => $product->name,
                'product_id' => $product->id,
                'category_id' => $product->category_id,
                'dimensions' => '10x20x30',
                'qty' => 2,
                'price' => 250000,
                'source' => 'Order',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $item = Order::query()
        ->where('order_id', 'WORKFLOW-CATALOG-REUSE')
        ->firstOrFail()
        ->items()
        ->firstOrFail();

    expect($item->status)->toBe('Produksi')
        ->and($item->factory_status)->toBe('Siap Produksi')
        ->and((float) $item->factory_sale_price)->toBe(125000.0)
        ->and($item->status_evidence_images)->toHaveCount(2)
        ->and($item->factory_drawing_image)->toStartWith('orders/products/');

    foreach ($item->status_evidence_images as $mockupPath) {
        Storage::disk('public')->assertExists($mockupPath);
    }
    Storage::disk('public')->assertExists($item->factory_drawing_image);
});

test('PF reuses the catalog mockup when saving an existing order without uploading a drawing', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $catalogMockupPath = 'catalog/mockups/pf-chair.png';
    $product = Product::factory()->create([
        'name' => 'Workflow Chair',
        'factory_mockup' => $catalogMockupPath,
    ]);
    Storage::disk('public')->put($catalogMockupPath, 'PF mockup');
    [$order, $item] = createWorkflowOrder('WORKFLOW-REUSE-CATALOG-MOCKUP', [
        'product_id' => $product->id,
        'factory_status' => 'None',
    ]);

    $factoryQueue = $this->actingAs($factoryAdmin)->get(route('order-products.index'));
    $factoryQueue
        ->assertSeeText('Mockup PF dari katalog akan digunakan jika gambar baru tidak diunggah.')
        ->assertSeeText('Simpan gambar PF');
    expect(preg_match('/<input\s+id="factory-drawing-'.$item->id.'"[^>]*>/', $factoryQueue->getContent(), $drawingInput))
        ->toBe(1);
    expect($drawingInput[0])->not->toContain('required');

    $this->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_sale_price' => '150,000',
        ])
        ->assertRedirect(route('order-products.index'));

    $updatedItem = $item->fresh();
    expect($updatedItem->factory_drawing_image)->toStartWith('orders/factory-drawings/')
        ->and($updatedItem->factory_status)->toBe('Siap Produksi')
        ->and((float) $updatedItem->factory_sale_price)->toBe(150000.0);
    Storage::disk('public')->assertExists($updatedItem->factory_drawing_image);
    expect($product->fresh()->factory_mockup)->toBe($catalogMockupPath);
});

test('PF can save its sale price before a photo without starting production', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products', 'orders'],
    ]);
    $product = Product::factory()->create(['name' => 'Workflow Chair']);
    [$order, $item] = createWorkflowOrder('WORKFLOW-NO-CATALOG-MOCKUP', [
        'product_id' => $product->id,
        'factory_status' => 'None',
    ]);

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_sale_price' => '150,000',
        ])
        ->assertRedirect(route('order-products.index'));

    expect($item->fresh()->factory_drawing_image)->toBeNull()
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and((float) $item->fresh()->factory_sale_price)->toBe(150000.0);

    $orderDetail = $this->get(route('orders.show', $order));
    $orderDetail
        ->assertSeeText('New')
        ->assertSeeText('Harga PF /pcs')
        ->assertSeeText('Total')
        ->assertSeeText('Rp 300.000');
    expect(preg_match('/<input\\s+id="factory-sale-price-'.$item->id.'"[^>]*>/', $orderDetail->getContent(), $salePriceInput))
        ->toBe(1);
    expect($salePriceInput[0])->not->toContain('required');

    $this->from(route('orders.show', $order))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('photo-after-price.png'),
        ])
        ->assertRedirect(route('orders.show', $order));

    expect($item->fresh()->factory_drawing_image)->not->toBeNull()
        ->and($item->fresh()->factory_status)->toBe('Siap Produksi')
        ->and((float) $item->fresh()->factory_sale_price)->toBe(150000.0);
});

test('Factory finishing moves partial quantities to packing and quality checks finish the order', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['order_products', 'orders'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-FINISHING', [
        'qty_completed' => 2,
        'factory_status' => 'Finishing',
        'status' => 'Finishing',
        'factory_drawing_image' => 'orders/factory-drawings/chair.png',
    ]);
    Storage::disk('public')->put('orders/factory-drawings/chair.png', 'drawing');
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('admin.finishing-assignments.store'), [
            'employee_id' => $employee->id,
            'order_item_id' => $item->id,
            'target_quantity' => 2,
            'is_rework' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Tugas finishing Harian dimulai dan tidak menambah upah harian.');

    $assignment = FinishingAssignment::query()->firstOrFail();
    expect($assignment->started_at)->not->toBeNull();

    $this->patch(route('admin.finishing-assignments.complete', $assignment), [
        'quantity' => 1,
    ])->assertRedirect();

    expect((int) $item->fresh()->packed_quantity)->toBe(1)
        ->and($assignment->fresh()->completion_note)->toBeNull()
        ->and($assignment->fresh()->remaining_quantity)->toBe(1);

    $this->patch(route('admin.finishing-assignments.complete', $assignment), [
        'quantity' => 1,
        'note' => 'Sisa unit selesai',
    ])->assertRedirect();

    expect((int) $item->fresh()->packed_quantity)->toBe(2)
        ->and($assignment->fresh()->completed_at)->not->toBeNull()
        ->and($employee->attendances()->count())->toBe(0);

    $this->actingAs($salesAdmin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Bagus',
            'quantity' => 1,
            'note' => 'Lolos QC',
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Dalam Perjalanan')
        ->and((int) $item->fresh()->quality_good_quantity)->toBe(1)
        ->and((int) $item->fresh()->packed_quantity)->toBe(1);

    $this->post(route('order-products.quality.store', $item), [
        'result' => 'Bagus',
        'quantity' => 1,
        'note' => 'Lolos QC',
    ])->assertRedirect();

    expect($item->fresh()->status)->toBe('Ready')
        ->and($order->fresh()->status)->toBe('Selesai');
});

test('damaged products enter PF Service and Harian can repair them without a PF drawing', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['order_products', 'orders'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-QC', [
        'qty_completed' => 2,
        'packed_quantity' => 2,
        'factory_status' => 'Packing',
        'status' => 'Dalam Perjalanan',
        'factory_sale_price' => 100000,
        'factory_handover_at' => now()->subDay(),
    ]);

    $this->actingAs($salesAdmin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Rusak',
            'quantity' => 1,
            'note' => 'Sambungan rusak',
            'evidence' => workflowImage('workflow-damaged.png'),
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Produksi')
        ->and($item->fresh()->factory_status)->toBe('Service')
        ->and($item->fresh()->factory_drawing_image)->toBeNull()
        ->and((int) $item->fresh()->quality_damaged_quantity)->toBe(1);
    expect($order->fresh()->status)->toBe('Proses Produksi');

    $factoryQueue = $this->actingAs($factoryAdmin)->get(route('order-products.index'));
    $factoryQueue
        ->assertSeeText('Service')
        ->assertSeeText('Gambar PF (opsional untuk Service)')
        ->assertSee('name="factory_sale_price"', false);
    expect(preg_match('/<input\s+id="factory-drawing-'.$item->id.'"[^>]*>/', $factoryQueue->getContent(), $drawingInput))
        ->toBe(1);
    expect($drawingInput[0])->not->toContain('required');
    expect(preg_match('/<input\s+id="factory-sale-price-queue-'.$item->id.'"[^>]*>/', $factoryQueue->getContent(), $queueSalePriceInput))
        ->toBe(1);
    expect($queueSalePriceInput[0])->toContain('value="100,000.00"');

    $factoryOrderDetail = $this->get(route('orders.show', $order));
    $factoryOrderDetail
        ->assertSeeText('Service')
        ->assertSeeText('Tidak wajib untuk Service.')
        ->assertSeeText('List Produk')
        ->assertDontSeeText('List Produk Saya')
        ->assertSeeText('Progres')
        ->assertSeeText('Harga PF')
        ->assertDontSeeText('Produksi ulang:')
        ->assertSee('name="factory_sale_price"', false);
    expect(preg_match('/<input\s+id="factory-sale-price-'.$item->id.'"[^>]*>/', $factoryOrderDetail->getContent(), $salePriceInput))
        ->toBe(1);
    expect($salePriceInput[0])->toContain('value="100,000.00"');

    $this->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_sale_price' => '150,000',
        ])
        ->assertRedirect(route('order-products.index'));

    expect($item->fresh()->factory_drawing_image)->toBeNull()
        ->and((float) $item->fresh()->factory_sale_price)->toBe(150000.0)
        ->and($item->fresh()->status)->toBe('Produksi')
        ->and($item->fresh()->factory_status)->toBe('Service');
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'type' => 'factory_sale_price_updated',
    ]);

    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Harian',
    ]);
    $this->actingAs($factoryAdmin)
        ->post(route('admin.finishing-assignments.store'), [
            'employee_id' => $employee->id,
            'order_item_id' => $item->id,
            'target_quantity' => 1,
            'is_rework' => 1,
        ])
        ->assertRedirect();

    $rework = FinishingAssignment::query()->firstOrFail();
    $this->patch(route('admin.finishing-assignments.complete', $rework), [
        'quantity' => 1,
        'note' => 'Perbaikan selesai',
    ])->assertRedirect();

    expect((int) $item->fresh()->quality_damaged_quantity)->toBe(0)
        ->and((int) $item->fresh()->packed_quantity)->toBe(2)
        ->and($item->fresh()->status)->toBe('Dalam Perjalanan')
        ->and($item->fresh()->factory_status)->toBe('Proses Kirim');

    $this->actingAs($salesAdmin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Bagus',
            'quantity' => 1,
        ])
        ->assertRedirect();

    $this->post(route('order-products.quality.store', $item), [
        'result' => 'Kurang',
        'quantity' => 1,
        'note' => 'Service Bali',
        'evidence' => workflowImage('workflow-short.png'),
    ])->assertRedirect();

    expect($item->fresh()->status)->toBe('Ready')
        ->and((int) $item->fresh()->quality_good_quantity)->toBe(1)
        ->and((int) $item->fresh()->quality_short_quantity)->toBe(1)
        ->and($order->fresh()->status)->toBe('Selesai');
});

test('PF can assign and produce remaining regular units while damaged units stay in Service', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-SERVICE-REMAINDER', [
        'qty' => 1000,
        'qty_completed' => 0,
        'quality_damaged_quantity' => 10,
        'factory_status' => 'Service',
        'factory_drawing_image' => 'orders/factory-drawings/service-remainder.png',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSeeText('Produksi 0/1000')
        ->assertSeeText('Service 0/10')
        ->assertSeeText('sisa 990');

    $this->post(route('orders.piecework-assignments.store', $order), [
        'employee_id' => $employee->id,
        'assignments' => [[
            'order_item_id' => $item->id,
            'target_quantity' => 990,
            'unit_price' => '25,000',
            'target_deadline' => now()->addDay()->toDateString(),
        ]],
    ])->assertRedirect(route('orders.show', $order));

    $assignment = $item->pieceworkAssignments()->firstOrFail();
    expect($assignment->is_rework)->toBeFalse()
        ->and((int) $assignment->target_quantity)->toBe(990)
        ->and($item->fresh()->factory_status)->toBe('Service');

    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [[
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'assignment_id' => $assignment->id,
            'product_name' => $item->product,
            'quantity' => 990,
            'unit_price' => '25,000',
            'target_deadline' => now()->addDay()->toDateString(),
        ]],
    ])->assertRedirect(route('admin.attendance.index', ['work_type' => 'Borongan']));

    expect((int) $item->fresh()->qty_completed)->toBe(990)
        ->and((int) $item->fresh()->quality_damaged_quantity)->toBe(10)
        ->and($item->fresh()->factory_status)->toBe('Service');
    $this->assertDatabaseHas('piecework_items', [
        'piecework_assignment_id' => $assignment->id,
        'quantity' => 990,
    ]);

    $this->get(route('orders.show', $order))
        ->assertSeeText('Produksi 990/1000')
        ->assertSeeText('Service 0/10');
});

test('PF can assign unfinished regular units after PB returns damaged units to Service', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['order_products', 'orders'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-RETURNED-DAMAGE', [
        'qty' => 1000,
        'qty_completed' => 20,
        'packed_quantity' => 10,
        'factory_status' => 'Proses Kirim',
        'factory_drawing_image' => 'orders/factory-drawings/returned-damage.png',
        'factory_handover_at' => now()->subDay(),
        'status' => 'Dalam Perjalanan',
    ]);

    $this->actingAs($salesAdmin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Rusak',
            'quantity' => 10,
            'evidence' => workflowImage('returned-damage.png'),
        ])
        ->assertRedirect();

    expect($item->fresh()->factory_status)->toBe('Service')
        ->and((int) $item->fresh()->qty_completed)->toBe(10)
        ->and((int) $item->fresh()->factory_production_qty_remaining)->toBe(980);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSee($item->product.' · sisa 980');

    $this->post(route('orders.piecework-assignments.store', $order), [
        'employee_id' => $employee->id,
        'assignments' => [[
            'order_item_id' => $item->id,
            'target_quantity' => 980,
            'unit_price' => '25,000',
            'target_deadline' => now()->addDay()->toDateString(),
        ]],
    ])->assertRedirect(route('orders.show', $order));

    expect((int) $item->pieceworkAssignments()->firstOrFail()->target_quantity)->toBe(980);
});

test('PF can continue regular production while earlier units are in transit', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-PARTIAL-SHIPMENT', [
        'qty' => 1000,
        'qty_completed' => 10,
        'quality_damaged_quantity' => 10,
        'factory_status' => 'Proses Kirim',
        'factory_drawing_image' => 'orders/factory-drawings/partial-shipment.png',
        'status' => 'Dalam Perjalanan',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSee($item->product.' · sisa 980');

    $this->post(route('orders.piecework-assignments.store', $order), [
        'employee_id' => $employee->id,
        'assignments' => [[
            'order_item_id' => $item->id,
            'target_quantity' => 980,
            'unit_price' => '25,000',
            'target_deadline' => now()->addDay()->toDateString(),
        ]],
    ])->assertRedirect(route('orders.show', $order));

    $assignment = $item->pieceworkAssignments()->firstOrFail();
    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [[
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'assignment_id' => $assignment->id,
            'product_name' => $item->product,
            'quantity' => 980,
            'unit_price' => '25,000',
            'target_deadline' => now()->addDay()->toDateString(),
        ]],
    ])->assertRedirect(route('admin.attendance.index', ['work_type' => 'Borongan']));

    expect((int) $item->fresh()->qty_completed)->toBe(990);
});

test('PB photo removal deletes one catalog image at a time and resets the item to New when empty', function () {
    Storage::fake('public');
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['orders'],
    ]);
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $catalogImages = ['catalog/mockups/pb-one.png', 'catalog/mockups/pb-two.png'];
    $orderImages = ['orders/status/pb-one.png', 'orders/status/pb-two.png'];
    $product = Product::factory()->create([
        'mockup' => $catalogImages[0],
        'mockups' => $catalogImages,
    ]);
    Storage::disk('public')->put($catalogImages[0], 'image-one');
    Storage::disk('public')->put($catalogImages[1], 'image-two');
    Storage::disk('public')->put($orderImages[0], 'image-one');
    Storage::disk('public')->put($orderImages[1], 'image-two');
    [$order, $item] = createWorkflowOrder('WORKFLOW-PB-PHOTO-DELETE', [
        'product_id' => $product->id,
        'status_images' => $orderImages,
        'status_image' => $orderImages[0],
        'factory_status' => 'None',
    ]);

    $this->actingAs($factoryAdmin)
        ->delete(route('order-products.images.destroy', [$item, 'pb', 0]))
        ->assertForbidden();

    $this->actingAs($salesAdmin)
        ->delete(route('order-products.images.destroy', [$item, 'pb', 0]))
        ->assertRedirect();

    expect($item->fresh()->status_evidence_images)->toBe([$orderImages[1]])
        ->and($item->fresh()->status)->toBe('Produksi')
        ->and($product->fresh()->mockupPaths())->toBe([$catalogImages[1]]);
    Storage::disk('public')->assertMissing([$catalogImages[0], $orderImages[0]]);
    Storage::disk('public')->assertExists([$catalogImages[1], $orderImages[1]]);

    $this->delete(route('order-products.images.destroy', [$item, 'pb', 0]))
        ->assertRedirect();

    expect($item->fresh()->status_evidence_images)->toBe([])
        ->and($item->fresh()->status)->toBe('None')
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and($product->fresh()->mockupPaths())->toBe([]);
    Storage::disk('public')->assertMissing([$catalogImages[1], $orderImages[1]]);
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'None',
    ]);
});

test('PF photo removal is PF-only and resets factory status to New', function () {
    Storage::fake('public');
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['orders'],
    ]);
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $catalogImage = 'catalog/mockups/factory.png';
    $orderImage = 'orders/factory-drawings/factory.png';
    $product = Product::factory()->create([
        'factory_mockup' => $catalogImage,
        'factory_sale_price' => 100000,
    ]);
    Storage::disk('public')->put($catalogImage, 'factory-image');
    Storage::disk('public')->put($orderImage, 'factory-image');
    [, $item] = createWorkflowOrder('WORKFLOW-PF-PHOTO-DELETE', [
        'product_id' => $product->id,
        'factory_status' => 'Siap Produksi',
        'factory_drawing_image' => $orderImage,
        'factory_sale_price' => 100000,
    ]);

    $this->actingAs($salesAdmin)
        ->delete(route('order-products.images.destroy', [$item, 'pf', 0]))
        ->assertForbidden();

    $this->actingAs($factoryAdmin)
        ->delete(route('order-products.images.destroy', [$item, 'pf', 0]))
        ->assertRedirect();

    expect($item->fresh()->factory_drawing_image)->toBeNull()
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and($product->fresh()->factory_mockup)->toBeNull();
    Storage::disk('public')->assertMissing([$catalogImage, $orderImage]);
});
