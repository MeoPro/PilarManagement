<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owner can create and deactivate an admin for a separate business unit', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('admin.users.store'), [
            'name' => 'Factory Admin',
            'email' => 'factory-admin@example.com',
            'business_unit' => User::UNIT_FACTORY,
            'accessible_menus' => ['orders', 'order_products'],
            'password' => 'factory-secret',
            'password_confirmation' => 'factory-secret',
        ])
        ->assertRedirect(route('admin.users.index'));

    $factoryAdmin = User::where('email', 'factory-admin@example.com')->firstOrFail();
    expect($factoryAdmin->role)->toBe(User::ROLE_ADMIN)
        ->and($factoryAdmin->business_unit)->toBe(User::UNIT_FACTORY)
        ->and($factoryAdmin->accessibleUnits())->toBe([User::UNIT_FACTORY])
        ->and($factoryAdmin->accessible_menus)->toBe(['orders', 'order_products']);

    $this->get(route('admin.users.show', $factoryAdmin))
        ->assertSee('factory-admin@example.com')
        ->assertSee('Pilar Factory')
        ->assertSee('Produk Orderan');

    $this->put(route('admin.users.update', $factoryAdmin), [
        'name' => $factoryAdmin->name,
        'email' => $factoryAdmin->email,
        'business_unit' => User::UNIT_FACTORY,
        'accessible_menus' => ['orders'],
        'is_active' => '0',
        'password' => '',
        'password_confirmation' => '',
    ])->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseHas('users', [
        'id' => $factoryAdmin->id,
        'is_active' => false,
        'business_unit' => User::UNIT_FACTORY,
        'accessible_units' => json_encode([User::UNIT_FACTORY]),
        'accessible_menus' => json_encode(['orders']),
    ]);

    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->post(route('login.store'), [
        'email' => $factoryAdmin->email,
        'password' => 'factory-secret',
    ])->assertSessionHasErrors('email');
    $this->get(route('login'));

    $this->actingAs($owner)
        ->delete(route('admin.users.destroy', $factoryAdmin))
        ->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseMissing('users', ['id' => $factoryAdmin->id]);
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'Akun admin dihapus',
        'target_id' => (string) $factoryAdmin->id,
    ]);
});

test('owner can assign only selected factory menus and change an account to bali', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('admin.users.store'), [
            'name' => 'Restricted Factory Admin',
            'email' => 'restricted-factory@example.com',
            'business_unit' => User::UNIT_FACTORY,
            'accessible_menus' => ['orders'],
            'password' => 'dual-secret',
            'password_confirmation' => 'dual-secret',
        ])
        ->assertRedirect(route('admin.users.index'));

    $admin = User::where('email', 'restricted-factory@example.com')->firstOrFail();
    expect($admin->accessible_units)->toBe([User::UNIT_FACTORY])
        ->and($admin->accessible_menus)->toBe(['orders'])
        ->and($admin->canManageSales())->toBeFalse()
        ->and($admin->isFactoryAdmin())->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.index'))
        ->assertOk()
        ->assertSee('Dashboard Pilar Factory')
        ->assertSee('Pilar Factory')
        ->assertSee('Daftar Order')
        ->assertDontSee('Produk Orderan')
        ->assertDontSee('Absensi')
        ->assertDontSee('Karyawan')
        ->assertDontSee('Daftar Tamu')
        ->assertDontSee('Kategori Produk')
        ->assertDontSee('Kategori Terlaris');

    $this->get(route('orders.index'))
        ->assertOk();
    $this->get(route('order-products.index'))
        ->assertForbidden();
    $this->get(route('admin.employees.index'))
        ->assertForbidden();
    $this->get(route('calendar.index'))
        ->assertForbidden();
    $this->get(route('guests.index'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'business_unit' => User::UNIT_BALI,
            'accessible_menus' => ['guests'],
            'is_active' => '1',
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertRedirect(route('admin.users.index'));

    expect($admin->fresh()->accessibleUnits())->toBe([User::UNIT_BALI])
        ->and($admin->fresh()->accessible_menus)->toBe(['guests']);
    $this->actingAs($admin->fresh())
        ->get(route('guests.index'))
        ->assertOk();
    $this->get(route('orders.index'))
        ->assertForbidden();
    $this->get(route('admin.employees.index'))
        ->assertForbidden();
    $this->get(route('calendar.index'))
        ->assertForbidden();
});

test('owner cannot create an account without selecting a unit menu', function () {
    $this->actingAs(User::factory()->owner()->create())
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), [
            'name' => 'No Unit Admin',
            'email' => 'no-unit@example.com',
            'accessible_menus' => [],
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
        ->assertSessionHasErrors(['business_unit', 'accessible_menus']);

    $this->assertDatabaseMissing('users', ['email' => 'no-unit@example.com']);
});

test('owner cannot assign menus from the other unit', function () {
    $this->actingAs(User::factory()->owner()->create())
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), [
            'name' => 'Factory Without Bali Menu',
            'email' => 'factory-no-bali@example.com',
            'business_unit' => User::UNIT_FACTORY,
            'accessible_menus' => ['guests'],
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
        ->assertSessionHasErrors('accessible_menus.0');

    $this->assertDatabaseMissing('users', ['email' => 'factory-no-bali@example.com']);
});

test('factory employee-only admin cannot access order routes', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('admin.users.store'), [
            'name' => 'Factory Employee Admin',
            'email' => 'factory-employee@example.com',
            'business_unit' => User::UNIT_FACTORY,
            'accessible_menus' => ['employees'],
            'password' => 'factory-secret',
            'password_confirmation' => 'factory-secret',
        ])
        ->assertRedirect(route('admin.users.index'));

    $admin = User::where('email', 'factory-employee@example.com')->firstOrFail();
    $this->actingAs($admin)
        ->get(route('admin.index'))
        ->assertSee('Pilar Factory')
        ->assertSee('Karyawan')
        ->assertDontSee('Absensi')
        ->assertDontSee('Daftar Order')
        ->assertDontSee('Daftar Tamu');
    $this->get(route('admin.employees.index'))
        ->assertOk();
    $this->get(route('admin.attendance.index'))
        ->assertForbidden();
    $this->get(route('orders.index'))
        ->assertForbidden();
    $this->get(route('order-products.index'))
        ->assertForbidden();
});

test('employee and attendance menus enforce separate access permissions', function () {
    $employee = Employee::create([
        'full_name' => 'Separate Menu Employee',
        'photo_path' => 'employees/separate-menu.jpg',
        'address' => 'Bali',
        'started_at' => today(),
        'status' => 'Aktif',
    ]);
    $employeeAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['employees'],
    ]);
    $attendanceAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance'],
    ]);

    $this->actingAs($employeeAdmin)
        ->get(route('admin.employees.index'))
        ->assertSee($employee->full_name);
    $this->get(route('admin.attendance.index'))
        ->assertForbidden();

    $this->actingAs($attendanceAdmin)
        ->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSee($employee->full_name);
    $this->get(route('admin.employees.index'))
        ->assertForbidden();
});

test('unit admins cannot manage accounts or inspect owner activity logs', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertForbidden();

    $this->get(route('admin.activity-log.index'))
        ->assertForbidden();
});

test('owner account from the seeder cannot be deleted as a managed admin', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->delete(route('admin.users.destroy', $owner))
        ->assertNotFound();

    $this->assertModelExists($owner);
});
