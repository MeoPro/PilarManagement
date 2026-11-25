<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\FactoryPieceworkPunishmentRule;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PieceworkAssignment;
use App\Models\PieceworkItem;
use App\Models\Subcontractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records Bali piecework without creating attendance and calculates each item total', function () {
    $this->travelTo('2026-11-16 08:15:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
        'daily_wage' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSee('Absensi Borongan')
        ->assertSee('Harga per barang')
        ->assertSeeText('+ Tambah Borongan')
        ->assertDontSeeText('Tambah tugas')
        ->assertSee('name="piecework_items[0][quantity]" value="1"', false)
        ->assertSee('Deadline')
        ->assertSee('Tanggal')
        ->assertSee(route('admin.employees.attendance', [$employee, 'morning']), false);

    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [
            [
                'product_name' => 'Kursi',
                'quantity' => 10,
                'unit_price' => '100,000',
                'target_deadline' => '2026-11-30',
            ],
        ],
    ])->assertRedirect()
        ->assertSessionHas('success', 'Hasil borongan Pilar Bali berhasil dicatat tanpa membuat absensi.');

    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('+ Tambah Borongan')
        ->assertSeeText('List Borongan (1)')
        ->assertSeeText('Hasil Borongan Hari Ini')
        ->assertSeeText('Sisa 14 hari')
        ->assertSeeText('Kursi')
        ->assertDontSeeText('Tambah tugas');

    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [
            [
                'product_name' => 'Meja',
                'quantity' => 2,
                'unit_price' => '50,000',
                'target_deadline' => '2026-12-01',
            ],
        ],
    ])->assertRedirect()
        ->assertSessionHas('success', 'Hasil borongan Pilar Bali berhasil dicatat tanpa membuat absensi.');

    expect(PieceworkItem::query()->where('employee_id', $employee->id)->count())->toBe(2);

    $item = PieceworkItem::query()->where('employee_id', $employee->id)->firstOrFail();

    $this->assertDatabaseHas('piecework_items', [
        'employee_attendance_id' => null,
        'employee_id' => $employee->id,
        'product_name' => 'Kursi',
        'quantity' => 10,
        'unit_price' => 100000,
        'line_total' => 1000000,
    ]);
    expect($item->target_deadline->toDateString())->toBe('2026-11-30');
    $this->assertDatabaseCount('employee_attendances', 0);
    $this->get(route('admin.payroll.index', ['period' => '2026-11', 'salary_type' => 'Borongan']))
        ->assertSeeText('Rp 1.100.000');
});

it('creates active Bali Borongan tasks and shows completion and cancellation actions', function () {
    $this->travelTo('2026-11-17 08:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'daily_wage' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('+ Tambah Borongan')
        ->assertSee(route('admin.employees.piecework-assignments.store', $employee), false)
        ->assertSeeText('Catat hasil langsung');

    $this->post(route('admin.employees.piecework-assignments.store', $employee), [
        'product_name' => 'Buat meja',
        'target_quantity' => 2,
        'unit_price' => '150,000',
        'target_deadline' => '2026-11-20',
    ])->assertRedirect(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSessionHas('success', 'Tugas borongan berhasil ditambahkan.');

    $assignment = PieceworkAssignment::query()->firstOrFail();
    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('Buat meja')
        ->assertSeeText('Baru')
        ->assertSeeText('Selesaikan tugas')
        ->assertSeeText('Batalkan tugas')
        ->assertSeeText('Semua');
    $this->get(route('admin.employees.attendance-history', $employee))
        ->assertSeeText('Buat meja')
        ->assertSeeText('Selesaikan tugas')
        ->assertSeeText('Batalkan tugas')
        ->assertSeeText('Semua');

    expect($assignment->completed_at)->toBeNull()
        ->and($assignment->cancelled_at)->toBeNull();
});

it('keeps employees in the attendance list for their single configured work type', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->admin()->create();
    $employeeWithAssignment = Employee::factory()->create([
        'full_name' => 'Harian With Active Piecework',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);
    $employeeWithoutAssignment = Employee::factory()->create([
        'full_name' => 'Harian Without Piecework',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);
    $boronganEmployee = Employee::factory()->create([
        'full_name' => 'Borongan Only Employee',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'daily_wage' => null,
    ]);
    PieceworkAssignment::factory()
        ->for($employeeWithAssignment)
        ->create(['product_name' => 'Custom Borongan']);

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSeeText($employeeWithAssignment->full_name)
        ->assertSeeText($employeeWithoutAssignment->full_name)
        ->assertDontSeeText($boronganEmployee->full_name);

    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertDontSeeText($employeeWithAssignment->full_name)
        ->assertDontSeeText($employeeWithoutAssignment->full_name)
        ->assertSeeText('Borongan Only Employee');

    $this->actingAs($admin)
        ->post(route('admin.employees.piecework-assignments.store', $employeeWithoutAssignment), [
            'product_name' => 'Pekerjaan yang tidak sesuai',
            'target_quantity' => 2,
            'unit_price' => '10,000',
            'target_deadline' => '2026-11-20',
        ])->assertSessionHasErrors('employee_id');
});

it('does not pay daily wages for Bali Borongan attendance', function () {
    $this->travelTo('2026-11-16 08:15:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'started_at' => '2026-11-02',
        'employee_type' => 'Borongan',
        'daily_wage' => 100000,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSee(route('admin.employees.attendance', [$employee, 'morning']), false)
        ->assertSee(route('admin.employees.piecework-production.store', $employee), false);

    $this->actingAs($admin)
        ->post(route('admin.employees.attendance', [$employee, 'morning']), [
            'work_type' => 'Borongan',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Status hadir sesi 1 berhasil dicatat.');
    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'work_type' => 'Borongan',
        'daily_pay_amount' => 0,
    ]);

    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [[
            'product_name' => 'Kursi',
            'quantity' => 1,
            'unit_price' => '100,000',
            'target_deadline' => '2026-11-30',
        ]],
    ])->assertRedirect()
        ->assertSessionHas('success', 'Hasil borongan Pilar Bali berhasil dicatat tanpa membuat absensi.');

    $this->assertDatabaseCount('employee_attendances', 1);
    $this->assertDatabaseCount('piecework_items', 1);
});

it('requires Factory Borongan products to reference an order and its product', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'started_at' => '2026-11-02',
        'employee_type' => 'Borongan',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.employees.attendance', [$employee, 'afternoon']), [
            'work_type' => 'Borongan',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Status hadir sesi 2 berhasil dicatat.');

    $this->from(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->post(route('admin.employees.piecework-production.store', $employee), [
            'piecework_items' => [[
                'product_name' => 'Unlinked product',
                'quantity' => 1,
                'unit_price' => '100,000',
                'target_deadline' => '2026-11-30',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['piecework_items.0.order_id', 'piecework_items.0.order_item_id']);

    $this->assertDatabaseCount('employee_attendances', 1);
    $this->assertDatabaseCount('piecework_items', 0);
});

it('loads Factory order products for a Factory admin', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Factory Filter Guest']);
    $order = Order::create([
        'order_id' => 'SPK-FACTORY-FILTER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Factory Filter Product',
        'qty' => 10,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/filter.png',
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('SPK-FACTORY-FILTER')
        ->assertSeeText('Factory Filter Product')
        ->assertSeeText('+ Tambah Borongan')
        ->assertSee(route('admin.employees.piecework-production.store', $employee), false);
});

it('blocks Factory piecework production for products allocated to an active Subcon assignment', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $guest = Guest::create(['name' => 'Subcon Conflict Guest']);
    $order = Order::create([
        'order_id' => 'SPK-SUBCON-BLOCKED',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $orderItem = $order->items()->create([
        'product' => 'Subcon Assigned Product',
        'qty' => 10,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/subcon.png',
    ]);
    $subcontractor = Subcontractor::factory()->create();
    $subcontractor->assignments()->create([
        'order_item_id' => $orderItem->id,
        'created_by_id' => $admin->id,
        'assigned_quantity' => 2,
        'target_deadline' => '2026-11-20',
        'qty_completed' => 0,
        'status' => 'Produksi',
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertDontSeeText('SPK-SUBCON-BLOCKED')
        ->assertDontSeeText('Subcon Assigned Product');

    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [[
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_name' => 'Subcon Assigned Product',
            'quantity' => 1,
            'unit_price' => '25,000',
            'target_deadline' => '2026-11-20',
        ]],
    ])->assertInvalid([
        'piecework_items.0.order_item_id' => 'Produk ini sudah dialokasikan ke Subcon dan tidak dapat dikerjakan oleh karyawan borongan PF.',
    ]);

    $this->post(route('order-items.piecework-assignments.store', $orderItem), [
        'employee_id' => $employee->id,
        'target_quantity' => 1,
        'unit_price' => '25,000',
        'target_deadline' => '2026-11-20',
    ])->assertInvalid([
        'target_quantity' => 'Produk ini sudah dialokasikan ke Subcon dan tidak dapat ditugaskan ke borongan PF.',
    ]);

    $this->get(route('orders.show', $order))
        ->assertSeeText('sudah dialokasikan ke Subcon dan tidak dapat ditugaskan ke borongan PF');

    $this->assertDatabaseCount('piecework_items', 0);
    $this->assertDatabaseCount('piecework_assignments', 0);
    expect((int) $orderItem->fresh()->qty_completed)->toBe(0);
});

it('assigns multiple order products to one Factory employee in a single form', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'Multi Product Worker',
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $guest = Guest::create(['name' => 'Multi Product Piecework Guest']);
    $order = Order::create([
        'order_id' => 'SPK-PIECEWORK-MULTI',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'deadline' => '2026-11-30',
        'factory_deadline' => '2026-11-25',
        'status' => 'Proses Produksi',
    ]);
    $chair = $order->items()->create([
        'product' => 'Multi Chair',
        'qty' => 6,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/multi-chair.png',
    ]);
    $table = $order->items()->create([
        'product' => 'Multi Table',
        'qty' => 4,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/multi-table.png',
    ]);
    $factoryDeadline = $order->fresh()->factory_deadline;
    $chairDeadline = $factoryDeadline->copy()->subDays(2)->toDateString();
    $tableDeadline = $factoryDeadline->copy()->subDay()->toDateString();

    $this->actingAs($admin)
        ->get(route('orders.show', $order))
        ->assertSee('data-factory-piecework-assignment-form', false)
        ->assertSee('data-factory-piecework-add', false)
        ->assertSee('data-deadline-days', false)
        ->assertSee('name="assignments[0][unit_price]"', false)
        ->assertSee('max="'.$factoryDeadline->toDateString().'"', false)
        ->assertSeeText('Multi Chair')
        ->assertSeeText('Multi Table')
        ->assertDontSee(route('order-items.piecework-assignments.store', $chair), false)
        ->assertDontSee(route('order-items.piecework-assignments.store', $table), false);

    $this->from(route('orders.show', $order))
        ->post(route('orders.piecework-assignments.store', $order), [
            'employee_id' => $employee->id,
            'assignments' => [[
                'order_item_id' => $chair->id,
                'target_quantity' => 3,
                'target_deadline' => $chairDeadline,
            ]],
        ])
        ->assertSessionHasErrors('assignments.0.unit_price');
    $this->assertDatabaseCount('piecework_assignments', 0);

    $this->post(route('orders.piecework-assignments.store', $order), [
        'employee_id' => $employee->id,
        'assignments' => [
            [
                'order_item_id' => $chair->id,
                'target_quantity' => 3,
                'unit_price' => '25,000',
                'target_deadline' => $chairDeadline,
            ],
            [
                'order_item_id' => $table->id,
                'target_quantity' => 2,
                'unit_price' => '30,000',
                'target_deadline' => $tableDeadline,
            ],
        ],
    ])->assertRedirect(route('orders.show', $order))
        ->assertSessionHas('success', 'Penugasan borongan berhasil ditambahkan.');

    $this->assertDatabaseCount('piecework_assignments', 2);
    $this->assertDatabaseHas('piecework_assignments', [
        'employee_id' => $employee->id,
        'order_item_id' => $chair->id,
        'target_quantity' => 3,
        'unit_price' => 25000,
        'target_deadline' => $chairDeadline.' 00:00:00',
    ]);
    $this->assertDatabaseHas('piecework_assignments', [
        'employee_id' => $employee->id,
        'order_item_id' => $table->id,
        'target_quantity' => 2,
        'unit_price' => 30000,
        'target_deadline' => $tableDeadline.' 00:00:00',
    ]);
});

it('records Factory piecework earnings at the assigned unit price', function () {
    $this->travelTo('2026-11-20 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'Factory Piecework Worker',
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $guest = Guest::create(['name' => 'Factory Payroll Guest']);
    $order = Order::create([
        'order_id' => 'SPK-FACTORY-PIECEWORK-PAYROLL',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'factory_deadline' => '2026-11-23',
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Factory Payroll Chair',
        'qty' => 5,
        'price' => 200,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Siap Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/payroll-chair.png',
    ]);

    $this->actingAs($admin)
        ->post(route('orders.piecework-assignments.store', $order), [
            'employee_id' => $employee->id,
            'assignments' => [[
                'order_item_id' => $item->id,
                'target_quantity' => 3,
                'unit_price' => '25,000',
                'target_deadline' => '2026-11-23',
            ]],
        ])
        ->assertRedirect(route('orders.show', $order));

    $assignment = PieceworkAssignment::query()->firstOrFail();
    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'unit_price' => 25000,
    ]);

    $this->patch(route('piecework-assignments.complete', $assignment), [
        'quantity' => 3,
        'note' => 'Tiga kursi selesai.',
    ])->assertRedirect(route('orders.show', $order));

    $this->assertDatabaseHas('piecework_items', [
        'employee_id' => $employee->id,
        'order_item_id' => $item->id,
        'piecework_assignment_id' => $assignment->id,
        'quantity' => 3,
        'unit_price' => 25000,
        'line_total' => 75000,
    ]);
    $this->get(route('admin.payroll.index', ['period' => '2026-11', 'salary_type' => 'Borongan']))
        ->assertSeeText('Rp 75.000');
});

it('adds unpaid legacy Factory assignment completions to payroll when their rate is entered', function () {
    $this->travelTo('2026-11-20 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $guest = Guest::create(['name' => 'Legacy Factory Payroll Guest']);
    $order = Order::create([
        'order_id' => 'SPK-FACTORY-LEGACY-PAYROLL',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Legacy Factory Payroll Chair',
        'qty' => 10,
        'qty_completed' => 10,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Finishing',
        'factory_status' => 'Finishing',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'order_item_id' => $item->id,
        'assigned_by_id' => $admin->id,
        'completed_by_id' => $admin->id,
        'product_name' => $item->product,
        'target_quantity' => 10,
        'completed_quantity' => 10,
        'unit_price' => null,
        'target_deadline' => '2026-11-20',
        'completed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('orders.show', $order))
        ->assertSeeText('Catat upah borongan');

    $this->patch(route('piecework-assignments.rate.update', $assignment), [
        'unit_price' => '15,000',
    ])->assertRedirect(route('orders.show', $order));

    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'completed_quantity' => 0,
        'unit_price' => 15000,
    ]);
    $this->assertDatabaseHas('piecework_items', [
        'employee_id' => $employee->id,
        'order_item_id' => $item->id,
        'piecework_assignment_id' => $assignment->id,
        'quantity' => 10,
        'unit_price' => 15000,
        'line_total' => 150000,
    ]);
    $this->get(route('admin.payroll.index', ['period' => '2026-11', 'salary_type' => 'Borongan']))
        ->assertSeeText('Rp 150.000');

    $this->patch(route('piecework-assignments.rate.update', $assignment), [
        'unit_price' => '15,000',
    ])->assertSessionHasErrors('unit_price');
    $this->assertDatabaseCount('piecework_items', 1);
});

it('rolls back all Factory piecework assignments when a product deadline exceeds its order deadline', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $guest = Guest::create(['name' => 'Overdue Multi Product Guest']);
    $order = Order::create([
        'order_id' => 'SPK-PIECEWORK-DEADLINE',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'deadline' => '2026-11-30',
        'factory_deadline' => '2026-11-25',
        'status' => 'Proses Produksi',
    ]);
    $chair = $order->items()->create([
        'product' => 'Deadline Chair',
        'qty' => 6,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/deadline-chair.png',
    ]);
    $table = $order->items()->create([
        'product' => 'Deadline Table',
        'qty' => 4,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/deadline-table.png',
    ]);
    $factoryDeadline = $order->fresh()->factory_deadline;
    $validDeadline = $factoryDeadline->toDateString();
    $invalidDeadline = $factoryDeadline->copy()->addDay()->toDateString();

    $this->actingAs($admin)
        ->post(route('orders.piecework-assignments.store', $order), [
            'employee_id' => $employee->id,
            'assignments' => [
                [
                    'order_item_id' => $chair->id,
                    'target_quantity' => 3,
                    'unit_price' => '25,000',
                    'target_deadline' => $validDeadline,
                ],
                [
                    'order_item_id' => $table->id,
                    'target_quantity' => 2,
                    'unit_price' => '30,000',
                    'target_deadline' => $invalidDeadline,
                ],
            ],
        ])->assertInvalid([
            'assignments.1.target_deadline' => 'Deadline penugasan borongan tidak boleh melebihi deadline order ('.$factoryDeadline->format('d/m/Y').').',
        ]);

    $this->assertDatabaseCount('piecework_assignments', 0);
});

it('assigns a single SPK product across factory employees without exceeding its order quantity', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Piecework Order Guest']);
    $order = Order::create([
        'order_id' => 'SPK-PIECEWORK-10',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $orderItem = $order->items()->create([
        'product' => 'SPK Chair',
        'qty' => 10,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/piecework.png',
    ]);
    $employees = collect(range(1, 3))->map(fn (int $number) => Employee::factory()->create([
        'full_name' => "Factory Worker {$number}",
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
        'daily_wage' => null,
    ]));

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('SPK-PIECEWORK-10')
        ->assertSeeText('SPK Chair');

    foreach ([6, 4] as $index => $quantity) {
        $this->post(route('admin.employees.piecework-production.store', $employees[$index]), [
            'piecework_items' => [[
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'product_name' => 'SPK Chair',
                'quantity' => $quantity,
                'unit_price' => '25,000',
                'target_deadline' => '2026-11-30',
            ]],
        ])->assertRedirect()
            ->assertSessionHas('success', 'Hasil borongan berhasil dicatat tanpa mengubah absensi.');
    }

    $this->from(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->post(route('admin.employees.piecework-production.store', $employees[2]), [
            'piecework_items' => [[
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'product_name' => 'SPK Chair',
                'quantity' => 1,
                'unit_price' => '25,000',
                'target_deadline' => '2026-11-30',
            ]],
        ])->assertSessionHasErrors('piecework_items.0.order_item_id');

    expect(PieceworkItem::query()->where('order_item_id', $orderItem->id)->sum('quantity'))->toBe(10);
    $this->assertDatabaseCount('employee_attendances', 0);

    $this->get(route('orders.show', $order))
        ->assertSeeText('Rincian Pekerjaan Factory')
        ->assertSeeText('Factory Worker 1')
        ->assertSeeText('Factory Worker 2')
        ->assertSeeText('6')
        ->assertSeeText('4');
});

it('allows editing an active Factory piecework assignment from the order detail', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Editable Piecework Guest']);
    $order = Order::create([
        'order_id' => 'SPK-EDIT-ASSIGNMENT',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $orderItem = $order->items()->create([
        'product' => 'Editable Chair',
        'qty' => 20,
        'price' => 50,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/editable.png',
    ]);
    $employees = collect(range(1, 2))->map(fn (int $number) => Employee::factory()->create([
        'full_name' => "Editable Worker {$number}",
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]));
    $assignment = PieceworkAssignment::factory()
        ->for($employees[0])
        ->create([
            'order_item_id' => $orderItem->id,
            'assigned_by_id' => $admin->id,
            'product_name' => 'Editable Chair',
            'target_quantity' => 10,
            'target_deadline' => '2026-11-20',
        ]);

    $this->actingAs($admin)
        ->get(route('orders.show', $order))
        ->assertSeeText('Edit penugasan')
        ->assertSee(route('piecework-assignments.update', $assignment), false);

    $this->post(route('admin.employees.piecework-production.store', $employees[0]), [
        'piecework_items' => [[
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'assignment_id' => $assignment->id,
            'product_name' => 'Editable Chair',
            'quantity' => 4,
            'unit_price' => '25,000',
            'target_deadline' => '2026-11-20',
        ]],
    ])->assertRedirect();

    $this->get(route('orders.show', $order))
        ->assertSee('min="4"', false);

    $this->patch(route('piecework-assignments.update', $assignment), [
        'employee_id' => $employees[1]->id,
        'target_quantity' => 6,
        'target_deadline' => '2026-11-21',
        'unit_price' => '30,000',
    ])->assertRedirect(route('orders.show', $order));

    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'employee_id' => $employees[1]->id,
        'target_quantity' => 6,
        'unit_price' => 30000,
        'target_deadline' => '2026-11-21 00:00:00',
    ]);
});

it('records assigned factory production asynchronously and enforces the employee target', function () {
    $this->travelTo('2026-11-16 13:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Assigned Piecework Guest']);
    $order = Order::create([
        'order_id' => 'SPK-ASSIGNED-1',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $orderItem = $order->items()->create([
        'product' => 'Assigned Chair',
        'qty' => 8,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/assigned.png',
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'order_item_id' => $orderItem->id,
        'assigned_by_id' => $admin->id,
        'product_name' => 'Assigned Chair',
        'target_quantity' => 2,
        'target_deadline' => '2026-11-18',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('List Borongan')
        ->assertSeeText('Assigned Chair')
        ->assertSeeText('SPK #SPK-ASSIGNED-1');

    $this->actingAs($admin)
        ->post(route('admin.employees.piecework-production.store', $employee), [
            'piecework_items' => [[
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'assignment_id' => $assignment->id,
                'product_name' => 'Incorrect submitted name',
                'quantity' => 2,
                'unit_price' => '25,000',
                'target_deadline' => '2026-11-18',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Hasil borongan berhasil dicatat tanpa mengubah absensi.');

    $this->assertDatabaseHas('piecework_items', [
        'piecework_assignment_id' => $assignment->id,
        'product_name' => 'Assigned Chair',
        'quantity' => 2,
    ]);
    expect((int) $orderItem->fresh()->qty_completed)->toBe(2);

    $this->travelTo('2026-11-17 13:00:00');
    $this->postJson(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [[
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'assignment_id' => $assignment->id,
            'product_name' => 'Assigned Chair',
            'quantity' => 1,
            'unit_price' => '25,000',
            'target_deadline' => '2026-11-18',
        ]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('piecework_items');

    expect(EmployeeAttendance::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

it('shows remaining days and lets attendance users edit, complete, and cancel Borongan quantities', function () {
    $this->travelTo('2026-11-16 08:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'assigned_by_id' => $admin->id,
        'product_name' => 'Kursi',
        'target_quantity' => 10,
        'unit_price' => 10000,
        'target_deadline' => '2026-11-20',
    ]);
    expect($assignment->completed_at)->toBeNull();

    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('List Borongan (1)')
        ->assertSeeText('Baru')
        ->assertSeeText('Sisa 4 hari')
        ->assertSeeText('Jumlah selesai')
        ->assertSeeText('Jumlah barang')
        ->assertSeeText('Selesaikan tugas')
        ->assertSeeText('Batalkan tugas')
        ->assertSeeText('Semua')
        ->assertSeeText('Simpan perubahan')
        ->assertSeeText('Alasan / catatan');

    $this->patch(route('admin.piecework-assignments.update', $assignment), [
        'product_name' => 'Kursi Ukir',
        'target_quantity' => 10,
        'unit_price' => '15,000',
        'target_deadline' => '2026-11-20',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Tugas Borongan berhasil diperbarui.');

    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'product_name' => 'Kursi Ukir',
        'target_quantity' => 10,
        'unit_price' => 15000,
    ]);

    $this->post(route('admin.piecework-assignments.cancel-quantity', $assignment), [
        'quantity' => 3,
        'note' => 'Jumlah pesanan dikurangi.',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Jumlah target Borongan berhasil dibatalkan.');
    expect((float) $assignment->fresh()->target_total)->toBe(105000.0);

    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [[
            'assignment_id' => $assignment->id,
            'product_name' => 'Kursi Ukir',
            'quantity' => 6,
            'unit_price' => '15,000',
            'target_deadline' => '2026-11-20',
        ]],
    ])->assertRedirect()
        ->assertSessionHas('success', 'Hasil borongan Pilar Bali berhasil dicatat tanpa membuat absensi.');

    $this->assertDatabaseHas('piecework_items', [
        'piecework_assignment_id' => $assignment->id,
        'quantity' => 6,
        'unit_price' => 15000,
    ]);
    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('Berjalan')
        ->assertSee('data-piecework-remaining="1"', false);

    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [[
            'assignment_id' => $assignment->id,
            'product_name' => 'Kursi Ukir',
            'quantity' => 2,
            'unit_price' => '15,000',
            'target_deadline' => '2026-11-20',
        ]],
    ])->assertSessionHasErrors('piecework_items');

    $this->patch(route('admin.piecework-assignments.update', $assignment), [
        'product_name' => 'Kursi Ukir',
        'target_quantity' => 8,
        'unit_price' => '15,000',
        'target_deadline' => '2026-11-20',
    ])->assertSessionHasErrors('target_quantity');

    $this->post(route('admin.piecework-assignments.cancel-quantity', $assignment), [
        'quantity' => 2,
        'note' => 'Target dibatalkan sebagian.',
    ])->assertSessionHasErrors('quantity');

    $this->post(route('admin.piecework-assignments.cancel-quantity', $assignment), [
        'quantity' => 1,
        'note' => 'Sisa target dibatalkan.',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Jumlah target Borongan berhasil dibatalkan.');

    $assignment = $assignment->fresh();
    expect($assignment->cancelled_at)->not->toBeNull()
        ->and((int) $assignment->cancelled_quantity)->toBe(4)
        ->and((int) $assignment->cancelled_by_id)->toBe($admin->id);

    $this->get(route('admin.performance.index', [
        'month' => '2026-11',
        'employee_type' => 'Borongan',
    ]))
        ->assertSeeText('100,0% produk selesai')
        ->assertSeeText('6 / 6 produk');
});

it('keeps partially completed and cancelled Borongan quantities open until the remaining work is completed', function () {
    $this->travelTo('2026-11-16 08:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'assigned_by_id' => $admin->id,
        'product_name' => 'Meja',
        'target_quantity' => 8,
        'unit_price' => 10000,
        'target_deadline' => '2026-11-20',
    ]);
    $this->actingAs($admin);

    $this->patch(route('admin.piecework-assignments.complete', $assignment), [
        'quantity' => 3,
        'note' => 'Tiga meja sudah diselesaikan.',
    ])->assertRedirect();
    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'completed_quantity' => 0,
        'completion_note' => 'Tiga meja sudah diselesaikan.',
        'completed_at' => null,
    ]);
    $this->assertDatabaseHas('piecework_items', [
        'piecework_assignment_id' => $assignment->id,
        'quantity' => 3,
        'unit_price' => 10000,
        'line_total' => 30000,
    ]);

    $this->post(route('admin.employees.piecework-production.store', $employee), [
        'piecework_items' => [[
            'assignment_id' => $assignment->id,
            'product_name' => 'Meja',
            'quantity' => 6,
            'unit_price' => '10,000',
            'target_deadline' => '2026-11-20',
        ]],
    ])->assertSessionHasErrors('piecework_items');

    $this->post(route('admin.piecework-assignments.cancel-quantity', $assignment), [
        'quantity' => 2,
        'note' => 'Dua unit dibatalkan oleh pelanggan.',
    ])->assertRedirect();
    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'cancelled_quantity' => 2,
        'cancellation_note' => 'Dua unit dibatalkan oleh pelanggan.',
        'cancelled_at' => null,
    ]);

    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSee('data-piecework-remaining="3"', false)
        ->assertSeeText('Selesaikan tugas');

    $this->patch(route('admin.piecework-assignments.complete', $assignment), [
        'quantity' => 3,
        'note' => 'Sisa pekerjaan diselesaikan.',
    ])->assertRedirect();
    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'completed_quantity' => 0,
        'completed_by_id' => $admin->id,
        'completion_note' => 'Sisa pekerjaan diselesaikan.',
        'cancelled_quantity' => 2,
    ]);
    expect($assignment->fresh()->completed_at)->not->toBeNull();
    expect((float) PieceworkItem::query()
        ->where('piecework_assignment_id', $assignment->id)
        ->sum('line_total'))->toBe(60000.0);
    $this->get(route('admin.payroll.index', ['period' => '2026-11', 'salary_type' => 'Borongan']))
        ->assertSeeText('Rp 60.000');
});

it('allows Borongan completion without a note and prevents exceeding the remaining quantity', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'assigned_by_id' => $admin->id,
        'product_name' => 'Kursi',
        'target_quantity' => 4,
        'unit_price' => 10000,
        'target_deadline' => '2026-11-20',
    ]);
    $this->actingAs($admin);

    $this->patch(route('admin.piecework-assignments.complete', $assignment), [
        'quantity' => 2,
    ])->assertRedirect();
    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'completion_note' => null,
        'completed_at' => null,
    ]);
    $this->assertDatabaseHas('piecework_items', [
        'piecework_assignment_id' => $assignment->id,
        'quantity' => 2,
    ]);

    $this->patch(route('admin.piecework-assignments.complete', $assignment), [
        'quantity' => 5,
        'note' => 'Jumlah tidak boleh melebihi target.',
    ])->assertSessionHasErrors('quantity');

    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'completed_quantity' => 0,
        'completed_at' => null,
    ]);
});

it('does not pay daily wages when a Borongan employee attends a Factory session', function () {
    $this->travelTo('2026-11-16 08:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'daily_wage' => 100000,
        'started_at' => '2026-11-02',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.employees.attendance', [$employee, 'morning']), [
            'work_type' => 'Borongan',
            'attendance_status' => 'Hadir',
            'piecework_items' => [],
        ])->assertRedirect()
        ->assertSessionHas('success', 'Status hadir sesi 1 berhasil dicatat.');

    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'session' => 'morning',
        'work_type' => 'Borongan',
        'attendance_status' => 'Hadir',
        'daily_pay_amount' => 0,
    ]);
});

it('records Factory production independently from attendance and pays only the produced piecework value', function () {
    $this->travelTo('2026-11-16 18:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'Independent Factory Worker',
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'daily_wage' => null,
        'started_at' => '2026-11-02',
    ]);
    $guest = Guest::create(['name' => 'Independent Factory Guest']);
    $order = Order::create([
        'order_id' => 'SPK-INDEPENDENT-PRODUCTION',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $orderItem = $order->items()->create([
        'product' => 'Independent Factory Product',
        'qty' => 5,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/independent.png',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.employees.piecework-production.store', $employee), [
            'piecework_items' => [[
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'product_name' => 'Independent Factory Product',
                'quantity' => 2,
                'unit_price' => '25,000',
                'target_deadline' => '2026-11-30',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Hasil borongan berhasil dicatat tanpa mengubah absensi.');

    $this->assertDatabaseHas('piecework_items', [
        'employee_id' => $employee->id,
        'employee_attendance_id' => null,
        'order_item_id' => $orderItem->id,
        'quantity' => 2,
        'line_total' => 50000,
    ]);
    expect((int) $orderItem->fresh()->qty_completed)->toBe(2);
    $this->assertDatabaseCount('employee_attendances', 0);

    $this->travelTo('2026-11-16 12:30:00');
    $this->post(route('admin.employees.attendance', [$employee, 'afternoon']), [
        'work_type' => 'Borongan',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Status hadir sesi 2 berhasil dicatat.');

    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'work_type' => 'Borongan',
        'attendance_status' => 'Hadir',
        'daily_pay_amount' => 0,
        'piecework_amount' => 0,
    ]);

    $this->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSeeText('Hasil borongan: Rp 50.000')
        ->assertSeeText('3,8% hadir');

    $this->get(route('admin.payroll.index', ['period' => '2026-11']))
        ->assertSeeText('Rp 50.000')
        ->assertSeeText('Rp 0');
});

it('restricts independent Factory production entry to Factory attendance access', function () {
    $admin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['performance'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.employees.piecework-production.store', $employee), [])
        ->assertForbidden();

    $this->assertDatabaseCount('piecework_items', 0);
});

it('does not count a PB borongan task as daily attendance', function () {
    $this->travelTo('2026-11-02 15:00:00');
    $owner = User::factory()->owner()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'PB Borongan Worker',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'daily_wage' => null,
        'started_at' => '2026-11-02',
    ]);
    $this->actingAs($owner)
        ->post(route('admin.employees.piecework-assignments.store', $employee), [
            'product_name' => 'PB Packaging',
            'target_quantity' => 12,
            'unit_price' => '25,000',
            'target_deadline' => '2026-11-04',
        ])
        ->assertRedirect(route('admin.attendance.index', ['work_type' => 'Borongan']));

    $assignment = PieceworkAssignment::firstOrFail();
    $this->patch(route('admin.piecework-assignments.update', $assignment), [
        'product_name' => 'PB Packaging Edited',
        'target_quantity' => 12,
        'unit_price' => '25,000',
        'target_deadline' => '2026-11-04',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Tugas Borongan berhasil diperbarui.');

    $this->assertDatabaseHas('piecework_assignments', [
        'id' => $assignment->id,
        'product_name' => 'PB Packaging Edited',
        'completed_at' => null,
        'completed_by_id' => null,
    ]);

    $assignment = $assignment->fresh();
    expect($assignment->completed_at)->toBeNull()
        ->and($assignment->completed_by_id)->toBeNull();
    expect((float) $assignment->unit_price)->toBe(25000.0)
        ->and($assignment->target_total)->toBe(300000.0);
    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('Harga per barang')
        ->assertSeeText('Total target')
        ->assertSeeText('Hari dari hari ini')
        ->assertSeeText('Deadline')
        ->assertSee('data-deadline-date', false)
        ->assertSee('data-deadline-days', false)
        ->assertDontSee('data-deadline-preview', false)
        ->assertSeeText('Baru')
        ->assertSeeText('Simpan perubahan')
        ->assertDontSeeText('Tugas borongan (opsional)')
        ->assertSee('piecework-dropdown-icon', false)
        ->assertSeeText('List Borongan')
        ->assertDontSee('data-deadline-mode', false);
    $this->travelTo('2026-11-05 15:00:00');

    $this->actingAs($owner)
        ->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSeeText('PB Borongan Worker')
        ->assertSeeText('0,0%');

    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('PB Packaging')
        ->assertSeeText('Terlambat · perlu tindak lanjut')
        ->assertSeeText('Catat jumlah selesai')
        ->assertSeeText('Selesaikan tugas');

    $this->get(route('admin.payroll.index', ['period' => '2026-11']))
        ->assertSeeText('Rp 0');

    expect(EmployeeAttendance::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

it('uses the assigned PB unit price for standalone production without attendance', function () {
    $this->travelTo('2026-11-16 08:15:00');
    $owner = User::factory()->owner()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'daily_wage' => null,
        'started_at' => '2026-11-02',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'product_name' => 'Custom service',
        'target_quantity' => 5,
        'unit_price' => 25000,
        'target_deadline' => '2026-11-20',
    ]);

    $this->actingAs($owner)
        ->post(route('admin.employees.piecework-production.store', $employee), [
            'piecework_items' => [[
                'assignment_id' => $assignment->id,
                'product_name' => 'Ignored submitted name',
                'quantity' => 2,
                'unit_price' => '1',
                'target_deadline' => '2026-11-20',
            ]],
        ])
        ->assertRedirect();

    $item = PieceworkItem::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($item->employee_attendance_id)->toBeNull()
        ->and((float) $item->unit_price)->toBe(25000.0)
        ->and((float) $item->line_total)->toBe(50000.0)
        ->and($item->product_name)->toBe('Custom service');
    $this->assertDatabaseCount('employee_attendances', 0);
});

it('does not count active Borongan work as daily attendance', function () {
    $this->travelTo('2026-11-04 15:00:00');
    $owner = User::factory()->owner()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'started_at' => '2026-11-02',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'product_name' => 'PB Product',
        'target_quantity' => 6,
        'target_deadline' => '2026-11-04',
    ]);
    $assignment->forceFill(['created_at' => '2026-11-02 09:00:00'])->save();
    PieceworkItem::create([
        'employee_id' => $employee->id,
        'piecework_assignment_id' => $assignment->id,
        'product_name' => 'PB Product',
        'quantity' => 1,
        'unit_price' => 10000,
        'line_total' => 10000,
        'target_deadline' => '2026-11-04',
    ]);

    $this->actingAs($owner)
        ->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSeeText('0,0%');
});

it('records standalone PB production for a Borongan employee without creating attendance', function () {
    $this->travelTo('2026-11-02 15:00:00');
    $owner = User::factory()->owner()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'Borongan Production Worker',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
        'daily_wage' => null,
    ]);

    $this->actingAs($owner)
        ->post(route('admin.employees.piecework-production.store', $employee), [
            'piecework_items' => [[
                'product_name' => 'Custom service',
                'quantity' => 2,
                'unit_price' => '15,000',
                'target_deadline' => '2026-11-04',
            ]],
        ])
        ->assertRedirect();

    $this->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSeeText('Borongan Production Worker')
        ->assertSeeText('0,0%');

    $this->assertDatabaseCount('employee_attendances', 0);
    $this->assertDatabaseHas('piecework_items', [
        'employee_id' => $employee->id,
        'employee_attendance_id' => null,
        'line_total' => 30000,
    ]);
    $this->get(route('admin.payroll.index', ['period' => '2026-11']))
        ->assertSeeText('Rp 30.000');
});

it('does not count PB assignments as attendance for either single work type', function () {
    $this->travelTo('2026-11-06 15:00:00');
    $owner = User::factory()->owner()->create();
    $boronganEmployee = Employee::factory()->create([
        'full_name' => 'Borongan Assignment Worker',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
        'daily_wage' => null,
    ]);
    $harianEmployee = Employee::factory()->create([
        'full_name' => 'Harian Assignment Worker',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'started_at' => '2026-11-02',
    ]);

    foreach ([$boronganEmployee, $harianEmployee] as $employee) {
        $assignment = PieceworkAssignment::create([
            'employee_id' => $employee->id,
            'product_name' => 'Custom service',
            'target_quantity' => 20,
            'target_deadline' => '2026-11-30',
        ]);
        $assignment->forceFill(['created_at' => '2026-11-02 09:00:00'])->save();
    }

    $this->actingAs($owner)
        ->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSeeText('Borongan Assignment Worker')
        ->assertSeeText('Harian Assignment Worker')
        ->assertDontSeeText('30,0%')
        ->assertSeeText('0,0%');
});

it('does not credit a Sunday or a Borongan task toward Harian attendance', function () {
    $this->travelTo('2026-11-09 15:00:00');
    $owner = User::factory()->owner()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'started_at' => '2026-11-06',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'product_name' => 'Custom service',
        'target_quantity' => 5,
        'target_deadline' => '2026-11-08',
    ]);
    $assignment->forceFill(['created_at' => '2026-11-06 09:00:00'])->save();

    $this->actingAs($owner)
        ->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSeeText('0,0%');
});

it('preserves Factory Borongan punishment history while its management page is removed', function () {
    $owner = User::factory()->owner()->create();
    $rule = FactoryPieceworkPunishmentRule::create([
        'target_salary' => 200000,
        'action_type' => 'description',
        'punishment' => 'Evaluasi historis',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get('/admin/punishments')
        ->assertNotFound();

    $this->assertDatabaseHas('factory_piecework_punishment_rules', [
        'id' => $rule->id,
        'punishment' => 'Evaluasi historis',
    ]);
});
