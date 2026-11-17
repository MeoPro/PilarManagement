<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows an admin to pin an accessible menu at the top of navigation', function () {
    $admin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'employees'],
    ]);

    $this->actingAs($admin)
        ->put(route('admin.menu-preferences.update'), [
            'menu' => 'attendance_daily',
            'pinned' => 1,
        ])
        ->assertRedirect();

    expect($admin->fresh()->pinned_menus)->toBe(['attendance_daily']);

    $this->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSee('Disematkan')
        ->assertSee('Absensi Harian')
        ->assertSee('Lepas sematan Absensi Harian');

    $this->put(route('admin.menu-preferences.update'), [
        'menu' => 'attendance_daily',
        'pinned' => 0,
    ])->assertRedirect();

    expect($admin->fresh()->pinned_menus)->toBe([]);
});

it('forbids an admin from pinning a menu outside their assigned access', function () {
    $admin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['employees'],
    ]);

    $this->actingAs($admin)
        ->put(route('admin.menu-preferences.update'), [
            'menu' => 'orders',
            'pinned' => 1,
        ])
        ->assertForbidden();

    expect($admin->fresh()->pinned_menus)->toBeNull();
});

it('switches the owner navigation menus with the global business unit focus', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('admin.index'))
        ->assertSeeText('Katalog Produk');

    $this->put(route('admin.business-unit-preferences.update'), [
        'business_unit' => User::UNIT_FACTORY,
    ])->assertRedirect(route('admin.index'));

    $this->get(route('admin.index'))
        ->assertSeeText('Produk Subcon')
        ->assertDontSeeText('Katalog Produk');
});

it('keeps the employee pin when the owner switches between business units', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->put(route('admin.menu-preferences.update'), [
            'menu' => 'employees',
            'pinned' => 1,
        ])
        ->assertRedirect();

    $this->put(route('admin.business-unit-preferences.update'), [
        'business_unit' => User::UNIT_FACTORY,
    ])->assertRedirect();

    $this->get(route('admin.employees.index'))
        ->assertSee('Disematkan')
        ->assertSee('Lepas sematan Karyawan');

    $this->put(route('admin.business-unit-preferences.update'), [
        'business_unit' => User::UNIT_BALI,
    ])->assertRedirect();

    $this->get(route('admin.employees.index'))
        ->assertSee('Disematkan')
        ->assertSee('Lepas sematan Karyawan');
});

it('returns to the current page when changing the business unit focus', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->from(route('admin.employees.index'))
        ->put(route('admin.business-unit-preferences.update'), [
            'business_unit' => User::UNIT_FACTORY,
        ])
        ->assertRedirect(route('admin.employees.index'));
});

it('groups attendance and orders and saves sidebar order per user', function () {
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance', 'orders', 'order_products'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSeeText('Absen')
        ->assertSeeText('Orderan')
        ->assertDontSeeText('SDM & Relasi');

    $this->get(route('admin.sidebar-preferences.edit'))
        ->assertOk()
        ->assertSeeText('Urutan Sidebar')
        ->assertSeeText('Absensi Harian')
        ->assertSeeText('Daftar Order')
        ->assertSee('data-sidebar-order-form', false)
        ->assertSee('draggable="true"', false)
        ->assertSee('data-sidebar-move="up"', false)
        ->assertSee('data-sidebar-group-drag-handle', false)
        ->assertSee('data-sidebar-group-move="up"', false);

    $this->put(route('admin.sidebar-preferences.update'), [
        'menu_order' => [
            'attendance_daily' => 1,
            'attendance_piecework' => 2,
            'kanban' => 3,
            'order_products' => 4,
            'orders' => 5,
        ],
    ])->assertRedirect(route('admin.sidebar-preferences.edit'));

    expect($admin->fresh()->sidebar_order)->toBe([
        'attendance_daily',
        'attendance_piecework',
        'kanban',
        'order_products',
        'orders',
    ]);

    $orderedNavigationResponse = $this->get(route('admin.index'));
    $orderedNavigationResponse->assertSeeInOrder([
        '<p class="navigation-label">Absen</p>',
        '<p class="navigation-label">Operasional</p>',
        '<p class="navigation-label">Orderan</p>',
    ], false);
    $orderedNavigation = $orderedNavigationResponse->getContent();
    expect(strpos($orderedNavigation, 'Produk Orderan'))
        ->toBeLessThan(strpos($orderedNavigation, 'Daftar Order'));

    $secondAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance', 'orders', 'order_products'],
    ]);
    $this->actingAs($secondAdmin);
    $defaultNavigation = $this->get(route('admin.index'))->getContent();
    expect(strpos($defaultNavigation, 'Daftar Order'))
        ->toBeLessThan(strpos($defaultNavigation, 'Produk Orderan'));
});

it('allows the owner to place the Pengaturan group at the top of the sidebar', function () {
    $owner = User::factory()->owner()->create();
    $owner->forceFill([
        'sidebar_order' => ['admin_users', 'attendance_schedule', 'activity_log', 'calendar'],
    ])->save();

    $this->actingAs($owner)
        ->get(route('admin.index'))
        ->assertSeeInOrder([
            '<p class="navigation-label">Pengaturan</p>',
            '<p class="navigation-label">Operasional</p>',
        ], false);
});

it('only allows ordering menus accessible to the current admin', function () {
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.sidebar-preferences.edit'))
        ->assertOk()
        ->assertSeeText('Absensi Harian')
        ->assertDontSeeText('Daftar Order');

    $this->put(route('admin.sidebar-preferences.update'), [
        'menu_order' => [
            'attendance_daily' => 1,
            'kanban' => 2,
            'orders' => 3,
        ],
    ])->assertSessionHasErrors('menu_order');

    expect($admin->fresh()->sidebar_order)->toBeNull();
});
