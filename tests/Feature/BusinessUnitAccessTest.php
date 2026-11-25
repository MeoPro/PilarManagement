<?php

use App\Models\Employee;
use App\Models\Guest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('factory admin can view production data without customer or PB price information', function () {
    $guest = Guest::create([
        'name' => 'Private Customer',
        'address' => 'Private Customer Address',
    ]);
    $order = Order::create([
        'order_id' => 'FACTORY-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'phone' => '08123456789',
        'total_order' => 950000,
        'paid_amount' => 125000,
        'deadline' => today()->addDays(4),
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Production Table',
        'qty' => 2,
        'price' => 475000,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->actingAs(User::factory()->factoryAdmin()->create());

    $this->get(route('orders.index'))
        ->assertSee('FACTORY-ORDER')
        ->assertSee('Production Table')
        ->assertDontSee('Private Customer')
        ->assertDontSee('08123456789')
        ->assertDontSee('Sisa Pembayaran Order Berjalan')
        ->assertDontSee('950,000')
        ->assertDontSee('125,000')
        ->assertDontSee('475,000');

    $this->get(route('orders.show', $order))
        ->assertSee('FACTORY-ORDER')
        ->assertSee('Production Table')
        ->assertDontSee('Private Customer')
        ->assertDontSee('Private Customer Address')
        ->assertDontSee('08123456789')
        ->assertDontSee('950,000')
        ->assertDontSee('125,000')
        ->assertDontSee('475,000');

    $this->get(route('admin.index'))
        ->assertSee('Dashboard Pilar Factory')
        ->assertSeeText('Order berjalan')
        ->assertSeeText('Produk diproduksi');
});

test('factory admin can update PF status but cannot change sales data', function () {
    Storage::fake('public');
    $guest = Guest::create(['name' => 'Sales Customer']);
    $order = Order::create([
        'order_id' => 'FACTORY-STATUS',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'phone' => '08111111111',
        'total_order' => 300000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Production Chair',
        'qty' => 1,
        'price' => 300000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Siap Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/production-chair.png',
        'factory_sale_price' => 125000,
    ]);
    $factoryAdmin = User::factory()->factoryAdmin()->create();

    $response = $this->actingAs($factoryAdmin)->patch(route('order-products.update', $item), [
        'items' => [
            $item->id => [
                'status' => 'Produksi',
                'qty_completed' => 0,
            ],
        ],
        'guest_name' => 'Tampered Customer',
        'phone' => '0000000000',
        'total_order' => 1,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'Proses Produksi',
        'guest_name' => 'Sales Customer',
        'phone' => '08111111111',
        'total_order' => 300000,
    ]);
    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'status' => 'Produksi',
        'price' => 300000,
        'factory_status' => 'Produksi',
        'factory_sale_price' => 125000,
    ]);
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'item_factory_status_changed',
    ]);
});

test('PB cannot reset a product after it has entered production', function () {
    $guest = Guest::create(['name' => 'Progress Customer']);
    $order = Order::create([
        'order_id' => 'FACTORY-PROGRESS',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Unfinished Table',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $order->items()->first()->id,
                'status' => 'None',
            ]],
        ])
        ->assertSessionHasErrors('items.0.status');

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'Proses Produksi',
    ]);
    $this->assertDatabaseCount('order_events', 0);
});

test('factory admin cannot access sales, guest, category, export, or cross-unit employee routes', function () {
    $baliEmployee = Employee::create([
        'full_name' => 'Bali Private Employee',
        'photo_path' => 'employees/bali.jpg',
        'address' => 'Bali Address',
        'business_unit' => User::UNIT_BALI,
        'started_at' => today(),
    ]);
    $factoryAdmin = User::factory()->factoryAdmin()->create();

    $this->actingAs($factoryAdmin)
        ->get(route('orders.create'))
        ->assertForbidden();
    $this->get(route('orders.edit', Order::create([
        'order_id' => 'LOCKED-ORDER',
        'guest_id' => Guest::create(['name' => 'Hidden Guest'])->id,
        'guest_name' => 'Hidden Guest',
        'total_order' => 100,
        'status' => 'None',
    ])))
        ->assertForbidden();
    $this->get(route('orders.export'))
        ->assertForbidden();
    $this->get(route('guests.index'))
        ->assertForbidden();
    $this->get(route('categories.index'))
        ->assertForbidden();
    $this->get(route('admin.employees.photo', $baliEmployee))
        ->assertNotFound();

    $this->get(route('admin.employees.index'))
        ->assertDontSee('Bali Private Employee');
});

test('bali admin cannot view factory employee records', function () {
    $factoryEmployee = Employee::create([
        'full_name' => 'Factory Private Employee',
        'photo_path' => 'employees/factory.jpg',
        'address' => 'Factory Address',
        'business_unit' => User::UNIT_FACTORY,
        'started_at' => today(),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.employees.edit', $factoryEmployee))
        ->assertNotFound();
});

test('factory admin employee creation remains scoped to factory despite a submitted bali unit', function () {
    Storage::fake('local');
    $factoryAdmin = User::factory()->factoryAdmin()->create();

    $this->actingAs($factoryAdmin)
        ->post(route('admin.employees.store'), [
            'full_name' => 'Factory Team Member',
            'photo' => UploadedFile::fake()->create('factory.jpg', 100, 'image/jpeg'),
            'address' => 'Factory Address',
            'business_unit' => User::UNIT_BALI,
            'employee_type' => 'Harian',
            'daily_wage' => '100,000',
            'started_at' => today()->toDateString(),
            'status' => 'Aktif',
        ])
        ->assertRedirect(route('admin.employees.index'));

    $this->assertDatabaseHas('employees', [
        'full_name' => 'Factory Team Member',
        'business_unit' => User::UNIT_FACTORY,
        'daily_wage' => 100000,
    ]);
});

test('owner employee list follows the persistent business unit focus', function () {
    Employee::create([
        'full_name' => 'Bali Team Member',
        'photo_path' => 'employees/bali-team.jpg',
        'address' => 'Bali',
        'business_unit' => User::UNIT_BALI,
        'started_at' => today(),
    ]);
    Employee::create([
        'full_name' => 'Factory Team Member',
        'photo_path' => 'employees/factory-team.jpg',
        'address' => 'Factory',
        'business_unit' => User::UNIT_FACTORY,
        'started_at' => today(),
    ]);

    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('admin.employees.index'))
        ->assertSee('Bali Team Member')
        ->assertDontSee('Factory Team Member');

    $this->put(route('admin.business-unit-preferences.update'), [
        'business_unit' => User::UNIT_FACTORY,
    ])->assertRedirect(route('admin.employees.index'));

    $this->get(route('admin.employees.index'))
        ->assertSee('Factory Team Member')
        ->assertDontSee('Bali Team Member');
});

test('admin with legacy multiple units is restricted to their selected unit', function () {
    $baliEmployee = Employee::create([
        'full_name' => 'Dual Access Bali Employee',
        'photo_path' => 'employees/dual-bali.jpg',
        'address' => 'Bali',
        'business_unit' => User::UNIT_BALI,
        'started_at' => today(),
    ]);
    $factoryEmployee = Employee::create([
        'full_name' => 'Dual Access Factory Employee',
        'photo_path' => 'employees/dual-factory.jpg',
        'address' => 'Factory',
        'business_unit' => User::UNIT_FACTORY,
        'started_at' => today(),
    ]);
    $admin = User::factory()->factoryAdmin()->create([
        'accessible_units' => [User::UNIT_BALI, User::UNIT_FACTORY],
    ]);

    expect($admin->accessibleUnits())->toBe([User::UNIT_FACTORY])
        ->and($admin->canManageSales())->toBeFalse();

    $this->actingAs($admin)
        ->get(route('admin.employees.index'))
        ->assertSee('Dual Access Factory Employee')
        ->assertDontSee('Dual Access Bali Employee')
        ->assertDontSee('Daftar Tamu');
    $this->get(route('admin.employees.edit', $factoryEmployee))
        ->assertOk();
    $this->get(route('admin.employees.edit', $baliEmployee))
        ->assertNotFound();
    $this->get(route('guests.index'))
        ->assertForbidden();
});
