<?php

use App\Models\Category;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PieceworkAssignment;
use App\Models\Product;
use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('owner can grant subcontractor access to a factory admin', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('admin.users.store'), [
            'name' => 'Factory Subcontractor Admin',
            'email' => 'factory-subcontractor@example.com',
            'business_unit' => User::UNIT_FACTORY,
            'accessible_menus' => ['subcontractors'],
            'password' => 'factory-secret',
            'password_confirmation' => 'factory-secret',
        ])
        ->assertRedirect(route('admin.users.index'));

    $admin = User::query()->where('email', 'factory-subcontractor@example.com')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('subcontractors.index'))
        ->assertSeeText('Subkontraktor');

    $this->actingAs($owner)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), [
            'name' => 'Bali Subcontractor Admin',
            'email' => 'bali-subcontractor@example.com',
            'business_unit' => User::UNIT_BALI,
            'accessible_menus' => ['subcontractors'],
            'password' => 'bali-secret',
            'password_confirmation' => 'bali-secret',
        ])
        ->assertSessionHasErrors('accessible_menus.0');
});

test('factory admins manage subcontractors and see completed order and category metrics', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['subcontractors'],
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('subcontractors.store'), [
            'name' => 'Bali Woodworks',
            'phone' => '08123456789',
            'email' => 'woodworks@example.com',
            'address' => 'Denpasar',
            'is_active' => '1',
        ])
        ->assertRedirect();

    $subcontractor = Subcontractor::query()->where('name', 'Bali Woodworks')->firstOrFail();
    $category = Category::create(['name' => 'Kursi']);
    $guest = Guest::create(['name' => 'Subcontractor Metrics Guest']);

    $completedOrder = Order::create([
        'order_id' => 'SUBCON-COMPLETE-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 200,
        'status' => 'Finishing',
    ]);
    $completedOrder->items()->create([
        'product' => 'Dining Chair',
        'category_id' => $category->id,
        'qty' => 2,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Distribusi',
        'factory_status' => 'None',
    ]);
    $completedOrder->items()->firstOrFail()->subcontractorAssignments()->create([
        'subcontractor_id' => $subcontractor->id,
        'assigned_quantity' => 2,
        'qty_completed' => 2,
        'status' => 'Distribusi',
    ]);

    $activeOrder = Order::create([
        'order_id' => 'SUBCON-ACTIVE-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 600,
        'status' => 'Proses Produksi',
    ]);
    $activeOrder->items()->create([
        'product' => 'Lounge Chair',
        'category_id' => $category->id,
        'qty' => 3,
        'price' => 200,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'None',
    ]);
    $activeOrder->items()->firstOrFail()->subcontractorAssignments()->create([
        'subcontractor_id' => $subcontractor->id,
        'assigned_quantity' => 3,
        'qty_completed' => 2,
        'status' => 'Produksi',
    ]);

    $this->get(route('subcontractors.show', $subcontractor))
        ->assertSeeText('40,0%')
        ->assertSeeText('1 / 2')
        ->assertSeeText('2 / 5')
        ->assertSeeText('Kursi')
        ->assertSeeText('Rp 800')
        ->assertSeeText('Rp 200');

    $this->put(route('subcontractors.update', $subcontractor), [
        'name' => 'Bali Woodworks',
        'phone' => '08123456789',
        'email' => 'woodworks@example.com',
        'address' => 'Denpasar',
        'is_active' => '0',
    ])->assertRedirect(route('subcontractors.show', $subcontractor));

    $this->assertDatabaseHas('subcontractors', [
        'id' => $subcontractor->id,
        'is_active' => false,
    ]);
});

test('factory admins allocate only the remaining product quantity to active subcontractors', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products', 'subcontractors'],
    ]);
    $subcontractor = Subcontractor::factory()->create(['name' => 'Active Subcon']);
    $inactiveSubcontractor = Subcontractor::factory()->create([
        'name' => 'Inactive Subcon',
        'is_active' => false,
    ]);
    $guest = Guest::create(['name' => 'Subcontractor Assignment Guest']);
    $order = Order::create([
        'order_id' => 'SUBCON-ASSIGNMENT-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Subcontractor Product',
        'qty' => 5,
        'qty_completed' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/subcontractor-product.png',
        'factory_sale_price' => 100,
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText('Active Subcon')
        ->assertDontSeeText('Inactive Subcon');

    $this->patch(route('order-products.update', $item), [
        'items' => [
            $item->id => [
                'status' => 'Produksi',
                'qty_completed' => 1,
            ],
        ],
    ])->assertRedirect();

    $this->post(route('order-products.subcontractor-assignments.store', $item), [
        'subcontractor_id' => $subcontractor->id,
        'quantity' => 2,
        'target_deadline' => '2026-12-01',
    ])->assertRedirect();

    $assignment = SubcontractorAssignment::query()->where('order_item_id', $item->id)->firstOrFail();

    $this->assertDatabaseHas('subcontractor_assignments', [
        'id' => $assignment->id,
        'subcontractor_id' => $subcontractor->id,
        'assigned_quantity' => 2,
        'target_deadline' => '2026-12-01 00:00:00',
        'qty_completed' => 0,
    ]);
    expect($item->fresh()->factory_quantity)->toBe(3);

    $this->get(route('subcontractor-products.index'))
        ->assertSeeText('Subcontractor Product')
        ->assertSeeText('01/12/2026');

    $this->get(route('subcontractor-products.index', ['view' => 'subcontractor']))
        ->assertSeeText('Active Subcon')
        ->assertSeeText('Subcontractor Product');

    $this->get(route('subcontractor-products.index', ['view' => 'order']))
        ->assertSeeText('SUBCON-ASSIGNMENT-ORDER')
        ->assertSeeText('Subcontractor Product');

    $this->get(route('subcontractor-products.index', ['view' => 'product']))
        ->assertSeeText('SUBCON-ASSIGNMENT-ORDER')
        ->assertSeeText('Subcontractor Product');

    $this->from(route('order-products.index'))
        ->post(route('order-products.subcontractor-assignments.store', $item), [
            'subcontractor_id' => $inactiveSubcontractor->id,
            'quantity' => 1,
        ])
        ->assertSessionHasErrors('subcontractor_id');

    $this->from(route('order-products.index'))
        ->post(route('order-products.subcontractor-assignments.store', $item), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 3,
        ])
        ->assertSessionHasErrors('quantity');

    expect(SubcontractorAssignment::query()->where('order_item_id', $item->id)->count())->toBe(1);
});

test('unfinished subcontractor quantity returns to factory while completed quantity stays with subcon', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products', 'subcontractors'],
    ]);
    $subcontractor = Subcontractor::factory()->create();
    $guest = Guest::create(['name' => 'Split Allocation Guest']);
    $order = Order::create([
        'order_id' => 'SUBCON-SPLIT-ALLOCATION',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 500,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Split Work Product',
        'qty' => 5,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/split-work.png',
        'factory_sale_price' => 100,
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('order-products.subcontractor-assignments.store', $item), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 3,
        ])
        ->assertRedirect();

    $assignment = SubcontractorAssignment::query()->firstOrFail();
    expect($item->fresh()->factory_quantity)->toBe(2);

    $this->get(route('subcontractors.show', $subcontractor))
        ->assertSeeText('Split Work Product')
        ->assertSeeText('0 / 3');

    $assignment->update(['qty_completed' => 1]);
    $this->patch(route('subcontractor-assignments.cancel', $assignment))
        ->assertRedirect();

    expect($item->fresh()->factory_quantity)->toBe(4);
    $this->assertDatabaseHas('subcontractor_assignments', [
        'id' => $assignment->id,
        'assigned_quantity' => 1,
        'qty_completed' => 1,
        'status' => 'Proses Kirim',
    ]);

    $this->post(route('order-products.subcontractor-assignments.store', $item), [
        'subcontractor_id' => $subcontractor->id,
        'quantity' => 1,
    ])->assertRedirect();

    $untouchedAssignment = SubcontractorAssignment::query()
        ->where('order_item_id', $item->id)
        ->where('id', '!=', $assignment->id)
        ->firstOrFail();
    $this->patch(route('subcontractor-assignments.cancel', $untouchedAssignment))
        ->assertRedirect();

    expect($item->fresh()->factory_quantity)->toBe(4);
    expect($untouchedAssignment->fresh()->cancelled_at)->not->toBeNull();
});

test('factory can allocate all remaining products in an order to one subcontractor', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'subcontractors'],
    ]);
    $subcontractor = Subcontractor::factory()->create();
    $guest = Guest::create(['name' => 'Bulk Subcontractor Guest']);
    $order = Order::create([
        'order_id' => 'SUBCON-BULK-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $firstItem = $order->items()->create([
        'product' => 'Bulk Chair',
        'qty' => 4,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/bulk-chair.png',
        'factory_sale_price' => 100,
    ]);
    $secondItem = $order->items()->create([
        'product' => 'Bulk Table',
        'qty' => 6,
        'qty_completed' => 2,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/bulk-table.png',
        'factory_sale_price' => 100,
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('orders.subcontractor-assignments.store', $order), [
            'subcontractor_id' => $subcontractor->id,
            'target_deadline' => '2026-12-10',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('subcontractor_assignments', [
        'order_item_id' => $firstItem->id,
        'assigned_quantity' => 4,
        'target_deadline' => '2026-12-10 00:00:00',
    ]);
    $this->assertDatabaseHas('subcontractor_assignments', [
        'order_item_id' => $secondItem->id,
        'assigned_quantity' => 4,
        'target_deadline' => '2026-12-10 00:00:00',
    ]);
    expect($firstItem->fresh()->factory_quantity)->toBe(0)
        ->and($secondItem->fresh()->factory_quantity)->toBe(2);
});

test('factory must allocate subcon per product when an order contains a piecework-assigned product', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'order_products', 'subcontractors'],
    ]);
    $subcontractor = Subcontractor::factory()->create();
    $guest = Guest::create(['name' => 'Mixed Piecework Guest']);
    $order = Order::create([
        'order_id' => 'SUBCON-PIECEWORK-GUARD',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 500,
        'status' => 'Proses Produksi',
    ]);
    $employeeAssignedItem = $order->items()->create([
        'product' => 'Employee Assigned Chair',
        'qty' => 2,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/employee-chair.png',
    ]);
    $availableItem = $order->items()->create([
        'product' => 'Available Table',
        'qty' => 2,
        'price' => 150,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/available-table.png',
        'factory_sale_price' => 150,
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
    ]);
    PieceworkAssignment::factory()
        ->for($employee)
        ->create([
            'order_item_id' => $employeeAssignedItem->id,
            'assigned_by_id' => $factoryAdmin->id,
            'product_name' => $employeeAssignedItem->product,
            'target_quantity' => 1,
        ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSeeText('ditugaskan ke karyawan borongan')
        ->assertSee(route('order-products.index'))
        ->assertDontSee('id="order-subcontractor"', false);

    $this->get(route('order-products.index'))
        ->assertSeeText('Employee Assigned Chair')
        ->assertSeeText('Available Table')
        ->assertSeeText('Produk sudah ditugaskan ke karyawan borongan')
        ->assertSee('id="subcontractor-'.$availableItem->id.'"', false)
        ->assertDontSee('id="subcontractor-'.$employeeAssignedItem->id.'"', false);

    $this->from(route('orders.show', $order))
        ->post(route('orders.subcontractor-assignments.store', $order), [
            'subcontractor_id' => $subcontractor->id,
        ])
        ->assertSessionHasErrors('subcontractor_id');

    $this->assertDatabaseCount('subcontractor_assignments', 0);

    $this->from(route('order-products.index'))
        ->post(route('order-products.subcontractor-assignments.store', $employeeAssignedItem), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 1,
        ])
        ->assertSessionHasErrors('subcontractor_id');

    $this->assertDatabaseCount('subcontractor_assignments', 0);

    $this->from(route('order-products.index'))
        ->post(route('order-products.subcontractor-assignments.store', $availableItem), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 2,
        ])
        ->assertRedirect(route('order-products.index'));

    $this->assertDatabaseHas('subcontractor_assignments', [
        'order_item_id' => $availableItem->id,
        'subcontractor_id' => $subcontractor->id,
        'assigned_quantity' => 2,
    ]);
    $this->assertDatabaseMissing('subcontractor_assignments', [
        'order_item_id' => $employeeAssignedItem->id,
    ]);
});

test('factory requires a PF photo before allocating a product to a subcontractor', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $subcontractor = Subcontractor::factory()->create();
    $order = Order::create([
        'order_id' => 'SUBCON-MISSING-PHOTO',
        'guest_name' => 'Missing Photo Guest',
        'total_order' => 100,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'No Photo Product',
        'qty' => 1,
        'price' => 100,
        'factory_sale_price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->post(route('order-products.subcontractor-assignments.store', $item), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 1,
        ])
        ->assertSessionHasErrors('subcontractor_id')
        ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('default')->first('subcontractor_id')
            === 'Unggah gambar kerja PF atau pastikan Mockup PF (gambar kedua) tersedia sebelum produk dialokasikan ke subcon.');

    expect($item->subcontractorAssignments()->exists())->toBeFalse();
});

test('factory accepts the catalog second photo and requires a selling price before subcontractor allocation', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $subcontractor = Subcontractor::factory()->create();
    $product = Product::factory()->create([
        'factory_mockup' => 'catalog/mockups/second-photo.png',
    ]);
    $order = Order::create([
        'order_id' => 'SUBCON-SECOND-PHOTO',
        'guest_name' => 'Second Photo Guest',
        'total_order' => 100,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => $product->name,
        'product_id' => $product->id,
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->post(route('order-products.subcontractor-assignments.store', $item), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 1,
        ])
        ->assertSessionHasErrors('subcontractor_id')
        ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('default')->first('subcontractor_id')
            === 'Harga jual per unit ke PB wajib disimpan sebelum produk dialokasikan ke subcon.');

    expect($item->subcontractorAssignments()->exists())->toBeFalse();

    $item->update(['factory_sale_price' => 100]);

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSee('id="subcontractor-'.$item->id.'"', false);

    $this->post(route('order-products.subcontractor-assignments.store', $item), [
        'subcontractor_id' => $subcontractor->id,
        'quantity' => 1,
    ])->assertRedirect();

    $this->assertDatabaseHas('subcontractor_assignments', [
        'order_item_id' => $item->id,
        'subcontractor_id' => $subcontractor->id,
        'assigned_quantity' => 1,
    ]);
});

test('factory bulk allocation rolls back when any eligible product lacks a PB selling price', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'subcontractors'],
    ]);
    $subcontractor = Subcontractor::factory()->create();
    $order = Order::create([
        'order_id' => 'SUBCON-BULK-MISSING-PRICE',
        'guest_name' => 'Missing Price Guest',
        'total_order' => 100,
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Priced Chair',
        'qty' => 1,
        'price' => 100,
        'factory_sale_price' => 100,
        'factory_drawing_image' => 'orders/factory-drawings/priced-chair.png',
        'source' => 'Order',
        'status' => 'Produksi',
    ]);
    $missingPriceItem = $order->items()->create([
        'product' => 'Unpriced Table',
        'qty' => 1,
        'price' => 100,
        'factory_drawing_image' => 'orders/factory-drawings/unpriced-table.png',
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->from(route('orders.show', $order))
        ->post(route('orders.subcontractor-assignments.store', $order), [
            'subcontractor_id' => $subcontractor->id,
        ])
        ->assertSessionHasErrors('subcontractor_id')
        ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('default')->first('subcontractor_id')
            === 'Harga jual per unit ke PB wajib disimpan sebelum produk dialokasikan ke subcon.');

    expect($order->items()->whereHas('subcontractorAssignments')->exists())->toBeFalse();
    expect($missingPriceItem->fresh()->subcontractorAssignments()->exists())->toBeFalse();
});

test('order product reaches distribution only after factory and subcon allocations are complete', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products', 'subcontractors'],
    ]);
    $subcontractor = Subcontractor::factory()->create();
    $guest = Guest::create(['name' => 'Split Progress Guest']);
    $order = Order::create([
        'order_id' => 'SUBCON-SPLIT-PROGRESS',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 500,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Split Progress Product',
        'qty' => 5,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/split-progress.png',
        'factory_sale_price' => 100,
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('order-products.subcontractor-assignments.store', $item), [
            'subcontractor_id' => $subcontractor->id,
            'quantity' => 2,
        ])
        ->assertRedirect();

    $assignment = SubcontractorAssignment::query()->firstOrFail();
    $this->patch(route('subcontractor-assignments.update', $assignment), [
        'qty_completed' => 2,
        'status' => 'Produksi',
    ])->assertRedirect();
    $this->assertDatabaseHas('subcontractor_assignments', [
        'id' => $assignment->id,
        'status' => 'Proses Kirim',
        'qty_completed' => 2,
    ]);

    $this->patch(route('subcontractor-assignments.update', $assignment), [
        'qty_completed' => 2,
        'status' => 'Distribusi',
    ])->assertRedirect();
    expect($item->fresh()->status)->toBe('Produksi');

    $this->patch(route('order-products.update', $item), [
        'items' => [
            $item->id => ['status' => 'Produksi', 'qty_completed' => 3],
        ],
    ])->assertRedirect();
    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'factory_status' => 'Distribusi',
        'status' => 'Distribusi',
    ]);

    $this->patch(route('order-products.update', $item), [
        'items' => [
            $item->id => [
                'status' => 'Distribusi',
                'qty_completed' => 3,
                'status_image' => UploadedFile::fake()->create('distribution.jpg', 100, 'image/jpeg'),
            ],
        ],
    ])->assertRedirect();

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Distribusi',
    ]);
    expect($order->fresh()->status)->toBe('Distribusi');
});

test('subcontractor management is unavailable outside factory menu access', function () {
    $baliAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['orders'],
    ]);
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    $subcontractor = Subcontractor::factory()->create();
    $guest = Guest::create(['name' => 'Restricted Subcontractor Guest']);
    $order = Order::create([
        'order_id' => 'RESTRICTED-SUBCON-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Restricted Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->actingAs($baliAdmin)
        ->get(route('subcontractors.index'))
        ->assertForbidden();

    $this->actingAs($factoryAdmin)
        ->get(route('subcontractors.index'))
        ->assertForbidden();

    $this->post(route('order-products.subcontractor-assignments.store', $item), [
        'subcontractor_id' => $subcontractor->id,
        'quantity' => 1,
    ])->assertForbidden();

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'subcontractor_id' => null,
    ]);
    $this->assertDatabaseMissing('subcontractor_assignments', [
        'order_item_id' => $item->id,
    ]);
});
