<?php

use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderItemCancellation;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('new orders use available Bali and PF stock before creating a production remainder', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create([
        'name' => 'Stock Split Chair',
        'price' => 100000,
        'factory_sale_price' => 40000,
        'dimensions' => '10x20x30',
    ]);
    ProductStock::create([
        'product_id' => $product->id,
        'business_unit' => User::UNIT_BALI,
        'quantity' => 1,
    ]);
    ProductStock::create([
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 2,
    ]);

    $this->actingAs($admin)
        ->post(route('orders.store'), [
            'order_id' => 'STOCK-SPLIT-ORDER',
            'guest_name' => 'Stock Split Guest',
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'paid_amount' => 0,
            'items' => [[
                'product' => $product->name,
                'product_id' => $product->id,
                'dimensions' => '10x20x30',
                'qty' => 5,
                'price' => 100000,
                'source' => 'Order',
                'status' => 'None',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $order = Order::query()->where('order_id', 'STOCK-SPLIT-ORDER')->firstOrFail();
    $items = $order->items()->orderBy('source')->get();

    expect($items)->toHaveCount(2);
    $this->assertDatabaseHas('order_items', [
        'order_id' => $order->id,
        'source' => 'Stock',
        'qty' => 3,
        'status' => 'Distribusi',
        'factory_status' => 'None',
        'stock_bali_quantity' => 1,
        'stock_factory_quantity' => 2,
        'factory_sale_price' => 40000,
    ]);
    $this->assertDatabaseHas('order_items', [
        'order_id' => $order->id,
        'source' => 'Order',
        'qty' => 2,
        'price' => 100000,
    ]);
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_BALI,
        'quantity' => 0,
    ]);
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 0,
    ]);
    expect((float) $order->fresh()->total_order)->toBe(500000.0);
});

test('ready products can be cancelled into the selected stock location', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Ready Cancellation Guest']);
    $product = Product::factory()->create([
        'factory_sale_price' => 25000,
    ]);
    $order = Order::create([
        'order_id' => 'READY-CANCELLATION',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 50000,
        'status' => 'Selesai',
    ]);
    $item = $order->items()->create([
        'product' => $product->name,
        'product_id' => $product->id,
        'qty' => 2,
        'price' => 25000,
        'source' => 'Order',
        'status' => 'Ready',
        'factory_status' => 'Ready',
        'factory_sale_price' => 25000,
    ]);

    $this->actingAs($admin)
        ->from(route('orders.show', $order))
        ->post(route('order-items.cancellations.store', $item), [
            'quantity' => 1,
            'stock_business_unit' => User::UNIT_BALI,
        ])
        ->assertRedirect(route('orders.show', $order));

    expect($item->fresh()->qty)->toBe(1)
        ->and((float) $order->fresh()->total_order)->toBe(25000.0);
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_BALI,
        'quantity' => 1,
    ]);
    $this->assertDatabaseHas('order_item_cancellations', [
        'order_item_id' => $item->id,
        'quantity' => 1,
        'decision' => 'ready_stock',
        'stock_business_unit' => User::UNIT_BALI,
    ]);
});

test('PF stock decision separates finished units from the internal production remainder', function () {
    $admin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'PF Cancellation Guest']);
    $product = Product::factory()->create([
        'name' => 'Cancelled Stock Chair',
        'factory_sale_price' => 40000,
        'dimensions' => '10x20x30',
    ]);
    $order = Order::create([
        'order_id' => 'PF-CANCELLATION',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => $product->name,
        'product_id' => $product->id,
        'dimensions' => '10x20x30',
        'qty' => 10,
        'qty_completed' => 5,
        'price' => 100,
        'factory_sale_price' => 40000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($admin)
        ->post(route('order-items.cancellations.store', $item), ['quantity' => 3])
        ->assertRedirect();
    $cancellation = OrderItemCancellation::query()->where('order_item_id', $item->id)->firstOrFail();

    $this->actingAs($factoryAdmin)
        ->patch(route('order-item-cancellations.update', $cancellation), [
            'decision' => 'stock',
            'stock_factory_quantity' => 2,
            'decision_note' => 'Dua unit selesai.',
        ])
        ->assertRedirect();

    $internalOrder = Order::query()->where('is_stock_order', true)->firstOrFail();
    $internalItem = $internalOrder->items()->firstOrFail();

    expect($item->fresh()->qty)->toBe(7)
        ->and((float) $order->fresh()->total_order)->toBe(700.0)
        ->and($cancellation->fresh()->decision)->toBe('stock')
        ->and($internalOrder->guest_name)->toBe('Stok Internal PF')
        ->and($internalItem->qty)->toBe(1)
        ->and($internalItem->source)->toBe('Order');
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 2,
    ]);

    $this->actingAs($admin)
        ->get(route('orders.index', ['search' => $internalOrder->order_id]))
        ->assertDontSeeText($internalOrder->order_id);
});

test('internal stock output can be added during production and is added only once', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $product = Product::factory()->create([
        'factory_sale_price' => 40000,
    ]);
    $order = Order::create([
        'order_id' => 'INTERNAL-STOCK-FINISHING',
        'guest_name' => 'Stok Internal PF',
        'total_order' => 0,
        'status' => 'Proses Produksi',
        'is_stock_order' => true,
    ]);
    $item = $order->items()->create([
        'product' => $product->name,
        'product_id' => $product->id,
        'qty' => 2,
        'qty_completed' => 0,
        'price' => 0,
        'factory_sale_price' => 40000,
        'source' => 'Order',
        'factory_drawing_image' => 'orders/factory-drawings/internal-stock.jpg',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->patch(route('order-products.update', $item), [
            'items' => [
                $item->id => [
                    'status' => 'Produksi',
                    'qty_completed' => 1,
                ],
            ],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 1,
    ]);
    expect($item->fresh()->stock_added_quantity)->toBe(1);

    $this->patch(route('order-products.update', $item), [
        'items' => [
            $item->id => [
                'status' => 'Produksi',
                'qty_completed' => 1,
            ],
        ],
    ])->assertRedirect();

    expect(ProductStock::query()
        ->where('product_id', $product->id)
        ->where('business_unit', User::UNIT_FACTORY)
        ->value('quantity'))->toBe(1);
});

test('PF rejection leaves the order quantity and stock unchanged', function () {
    $admin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Rejected Cancellation Guest']);
    $product = Product::factory()->create(['factory_sale_price' => 40000]);
    $order = Order::create([
        'order_id' => 'REJECT-CANCELLATION',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 300,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => $product->name,
        'product_id' => $product->id,
        'qty' => 3,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($admin)
        ->post(route('order-items.cancellations.store', $item), ['quantity' => 1])
        ->assertRedirect();
    $cancellation = OrderItemCancellation::query()->where('order_item_id', $item->id)->firstOrFail();

    $this->actingAs($factoryAdmin)
        ->patch(route('order-item-cancellations.update', $cancellation), [
            'decision' => 'rejected',
        ])
        ->assertRedirect();

    expect($item->fresh()->qty)->toBe(3)
        ->and((float) $order->fresh()->total_order)->toBe(300.0)
        ->and($cancellation->fresh()->decision)->toBe('rejected');
    $this->assertDatabaseMissing('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
    ]);
    $this->assertDatabaseMissing('orders', [
        'is_stock_order' => true,
    ]);
});

test('new stock is added to PF and can be transferred between both locations', function () {
    $admin = User::factory()->admin()->create(['accessible_menus' => ['product_stock']]);
    $product = Product::factory()->create([
        'price' => 80000,
        'factory_sale_price' => 40000,
    ]);

    $this->actingAs($admin)
        ->post(route('product-stock.add', $product), ['quantity' => 10])
        ->assertRedirect();
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 10,
    ]);

    $this->post(route('product-stock.transfer', $product), [
        'source_business_unit' => User::UNIT_FACTORY,
        'destination_business_unit' => User::UNIT_BALI,
        'quantity' => 4,
    ])->assertRedirect();
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 6,
    ]);
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_BALI,
        'quantity' => 4,
    ]);

    $this->post(route('product-stock.transfer', $product), [
        'source_business_unit' => User::UNIT_BALI,
        'destination_business_unit' => User::UNIT_FACTORY,
        'quantity' => 2,
    ])->assertRedirect();
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 8,
    ]);
    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_BALI,
        'quantity' => 2,
    ]);

    $this->get(route('product-stock.index'))
        ->assertSeeText('Harga')
        ->assertSeeText('Stok Factory')
        ->assertSeeText('Stok Bali')
        ->assertSeeText('Tambah stok')
        ->assertSeeText('Factory · saldo 8 unit')
        ->assertSeeText('Bali · saldo 2 unit')
        ->assertDontSeeText('Harga PB / unit')
        ->assertDontSeeText('Harga PF / unit')
        ->assertSeeText('Rp 80.000')
        ->assertDontSeeText('Rp 40.000');

    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['product_stock'],
    ]);
    $this->actingAs($factoryAdmin)
        ->get(route('product-stock.index'))
        ->assertSeeText('Rp 40.000')
        ->assertDontSeeText('Rp 80.000');
});

test('stock transfer rejects unavailable quantities without changing either balance', function () {
    $admin = User::factory()->admin()->create(['accessible_menus' => ['product_stock']]);
    $product = Product::factory()->create(['factory_sale_price' => 40000]);
    ProductStock::create([
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 2,
    ]);

    $this->actingAs($admin)
        ->from(route('product-stock.index'))
        ->post(route('product-stock.transfer', $product), [
            'source_business_unit' => User::UNIT_FACTORY,
            'destination_business_unit' => User::UNIT_BALI,
            'quantity' => 3,
        ])
        ->assertRedirect(route('product-stock.index'))
        ->assertSessionHasErrors([
            'quantity' => 'Saldo stok Factory hanya 2 unit; jumlah yang diminta 3 unit.',
        ]);

    $this->assertDatabaseHas('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_FACTORY,
        'quantity' => 2,
    ]);
    $this->assertDatabaseMissing('product_stocks', [
        'product_id' => $product->id,
        'business_unit' => User::UNIT_BALI,
    ]);
});

test('owner only sees the active unit price on the stock list', function () {
    $owner = User::factory()->owner()->create();
    Product::factory()->create([
        'name' => 'Product with separate prices',
        'price' => 80000,
        'factory_sale_price' => 40000,
    ]);

    $this->actingAs($owner)
        ->get(route('product-stock.index'))
        ->assertSeeText('Harga')
        ->assertSeeText('Stok Factory')
        ->assertSeeText('Stok Bali')
        ->assertSeeText('Tambah stok')
        ->assertSeeText('Rp 80.000')
        ->assertDontSeeText('Harga PF / unit')
        ->assertDontSeeText('Rp 40.000');

    $this->actingAs($owner)
        ->put(route('admin.business-unit-preferences.update'), [
            'business_unit' => User::UNIT_FACTORY,
        ]);

    $this->actingAs($owner)
        ->get(route('product-stock.index'))
        ->assertSeeText('Harga')
        ->assertSeeText('Stok Factory')
        ->assertSeeText('Stok Bali')
        ->assertSeeText('Tambah stok')
        ->assertSeeText('Rp 40.000')
        ->assertDontSeeText('Harga PB / unit')
        ->assertDontSeeText('Rp 80.000');
});
