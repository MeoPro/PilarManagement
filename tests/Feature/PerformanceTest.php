<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PieceworkAssignment;
use App\Models\PieceworkItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows monthly attendance and moves annual and absence-share details into employee details', function () {
    $this->travelTo('2026-11-03 15:00:00');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance', 'performance'],
    ]);
    $employee = Employee::factory()->create([
        'full_name' => 'Performance Harian',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'started_at' => '2026-11-02',
    ]);

    foreach ([
        ['2026-11-02', 'morning', 'Hadir'],
        ['2026-11-02', 'afternoon', 'Izin'],
        ['2026-11-03', 'morning', 'Alpa'],
    ] as [$date, $session, $status]) {
        EmployeeAttendance::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'session' => $session,
            'work_type' => 'Harian',
            'attendance_status' => $status,
            'attended_at' => "{$date} 08:00:00",
        ]);
    }

    $this->actingAs($admin)
        ->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSee('Performance Harian')
        ->assertSee('25,0%')
        ->assertSeeText('Performa Tahun 2026')
        ->assertSeeText('Izin 1 · Alpa 2')
        ->assertSeeText('Alpa 66,7%')
        ->assertSeeText('Izin 33,3%')
        ->assertDontSeeText('Rata-rata Alpa')
        ->assertDontSeeText('Rata-rata Izin')
        ->assertSee('Riwayat &amp; koreksi', false);
});

it('updates dashboard averages when the employee type filter changes', function () {
    $this->travelTo('2026-11-02 17:00:00');
    $admin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['performance'],
    ]);
    $dailyEmployee = Employee::factory()->create([
        'full_name' => 'Dashboard Harian',
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Harian',
        'started_at' => '2026-11-02',
    ]);
    $pieceworkEmployee = Employee::factory()->create([
        'full_name' => 'Dashboard Borongan',
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);

    foreach (['morning', 'afternoon'] as $session) {
        EmployeeAttendance::create([
            'employee_id' => $dailyEmployee->id,
            'attendance_date' => '2026-11-02',
            'session' => $session,
            'work_type' => 'Harian',
            'attendance_status' => 'Hadir',
            'attended_at' => '2026-11-02 08:00:00',
        ]);
    }
    EmployeeAttendance::create([
        'employee_id' => $pieceworkEmployee->id,
        'attendance_date' => '2026-11-02',
        'session' => 'morning',
        'work_type' => 'Borongan',
        'attendance_status' => 'Hadir',
        'piecework_amount' => 10000,
        'attended_at' => '2026-11-02 08:00:00',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.performance.index', ['employee_type' => 'Harian']))
        ->assertSeeText('Dashboard Harian')
        ->assertDontSeeText('Dashboard Borongan')
        ->assertSeeText('100,0%')
        ->assertSee('onchange="this.form.submit()"', false);

    $this->get(route('admin.performance.index', ['employee_type' => 'Borongan']))
        ->assertSeeText('Dashboard Borongan')
        ->assertDontSeeText('Dashboard Harian')
        ->assertSeeText('50,0%')
        ->assertSeeText('Dashboard Performa Karyawan');
});

it('shows Alpa and Izin shares based on recorded and unrecorded attendance sessions', function () {
    $this->travelTo('2026-11-06 15:00:00');
    $admin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['performance'],
    ]);
    $weekdays = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'];
    $cases = [
        ['No Alpa Share', ['Izin'], 'Alpa 0,0%', 'Izin 100,0%'],
        ['Moderate Alpa Share', ['Alpa', 'Izin', 'Izin', 'Izin', 'Izin'], 'Alpa 20,0%', 'Izin 80,0%'],
        ['High Alpa Share', ['Alpa', 'Alpa', 'Alpa', 'Alpa', 'Izin'], 'Alpa 80,0%', 'Izin 20,0%'],
    ];

    foreach ($cases as [$name, $statuses]) {
        $employee = Employee::factory()->create([
            'full_name' => $name,
            'business_unit' => User::UNIT_FACTORY,
            'started_at' => '2026-11-02',
        ]);

        $sessionStatuses = array_pad($statuses, count($weekdays) * 2, 'Hadir');
        foreach ($weekdays as $dayIndex => $date) {
            foreach (['morning', 'afternoon'] as $sessionIndex => $session) {
                EmployeeAttendance::create([
                    'employee_id' => $employee->id,
                    'attendance_date' => $date,
                    'session' => $session,
                    'work_type' => 'Harian',
                    'attendance_status' => $sessionStatuses[$dayIndex * 2 + $sessionIndex],
                    'attended_at' => "{$date} 08:00:00",
                ]);
            }
        }

    }

    $response = $this->actingAs($admin)
        ->get(route('admin.performance.index', ['month' => '2026-11']));

    $response->assertSeeText('No Alpa Share')
        ->assertSeeText('Alpa 0,0%')
        ->assertSeeText('Izin 100,0%')
        ->assertSeeText('Moderate Alpa Share')
        ->assertSeeText('Alpa 20,0%')
        ->assertSeeText('Izin 80,0%')
        ->assertSeeText('High Alpa Share')
        ->assertSeeText('Alpa 80,0%')
        ->assertSeeText('Izin 20,0%');
});

it('shows all Bali employee types by default and lets users select Harian', function () {
    $dailyEmployee = Employee::factory()->create([
        'full_name' => 'Karyawan Harian Bali',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
    ]);
    Employee::factory()->create([
        'full_name' => 'Karyawan Borongan Bali',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
    ]);
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['performance'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.performance.index'))
        ->assertSee($dailyEmployee->full_name)
        ->assertSee('Karyawan Borongan Bali')
        ->assertDontSee('Riwayat &amp; koreksi', false);

    $this->get(route('admin.performance.index', ['employee_type' => 'Harian']))
        ->assertSee($dailyEmployee->full_name)
        ->assertDontSee('Karyawan Borongan Bali');

    $this->get(route('admin.attendance.index'))
        ->assertForbidden();

    $adminWithoutPerformance = User::factory()->admin()->create([
        'accessible_menus' => ['attendance'],
    ]);
    $this->actingAs($adminWithoutPerformance)
        ->get(route('admin.performance.index'))
        ->assertForbidden();
});

it('shows Bali Borongan performance for jobs assigned in the selected month', function () {
    $this->travelTo('2026-11-20 15:00:00');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['performance'],
    ]);
    $employee = Employee::factory()->create([
        'full_name' => 'PB Dual Performance',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $employeeWithoutPiecework = Employee::factory()->create([
        'full_name' => 'PB Without Job',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $onTimeAssignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'product_name' => 'Job selesai tepat waktu',
        'target_quantity' => 10,
        'target_deadline' => '2026-11-15',
        'completed_at' => '2026-11-14 15:00:00',
    ]);
    $lateAssignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'product_name' => 'Job terlambat',
        'target_quantity' => 10,
        'target_deadline' => '2026-11-18',
        'completed_at' => '2026-11-20 15:00:00',
    ]);
    PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'product_name' => 'Job belum jatuh tempo',
        'target_quantity' => 10,
        'target_deadline' => '2026-11-30',
    ]);

    foreach ([
        [$onTimeAssignment, 7],
        [$lateAssignment, 3],
    ] as [$assignment, $quantity]) {
        PieceworkItem::create([
            'employee_id' => $employee->id,
            'piecework_assignment_id' => $assignment->id,
            'product_name' => $assignment->product_name,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'line_total' => $quantity * 1000,
            'target_deadline' => $assignment->target_deadline->toDateString(),
        ]);
    }

    $this->actingAs($admin)
        ->get(route('admin.performance.index', [
            'month' => '2026-11',
            'employee_type' => 'Borongan',
        ]))
        ->assertSeeText($employee->full_name)
        ->assertDontSeeText($employeeWithoutPiecework->full_name)
        ->assertSeeText('33,3% produk selesai')
        ->assertSeeText('10 / 30 produk')
        ->assertSeeText('50,0% tepat waktu')
        ->assertSeeText('1 / 2 tugas tepat waktu')
        ->assertSeeText('Rata-rata produk selesai')
        ->assertSeeText('Rata-rata ketepatan waktu')
        ->assertDontSeeText('Rata-rata Alpa');
});

it('shows PF borongan production progress and wages instead of attendance percentages', function () {
    $this->travelTo('2026-11-16 15:00:00');
    $admin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['performance'],
    ]);
    $employee = Employee::factory()->create([
        'full_name' => 'PF Piecework Worker',
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
    ]);
    $assignment = PieceworkAssignment::create([
        'employee_id' => $employee->id,
        'product_name' => 'Factory Chair',
        'target_quantity' => 8,
        'target_deadline' => '2026-11-30',
    ]);
    $attendance = EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-16',
        'session' => 'afternoon',
        'work_type' => 'Borongan',
        'attendance_status' => 'Hadir',
        'piecework_amount' => 75000,
        'attended_at' => '2026-11-16 13:00:00',
    ]);
    $attendance->pieceworkItems()->create([
        'piecework_assignment_id' => $assignment->id,
        'product_name' => 'Factory Chair',
        'quantity' => 2,
        'unit_price' => 37500,
        'line_total' => 75000,
        'target_deadline' => '2026-11-30',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSeeText('25,0% target jumlah')
        ->assertSeeText('2 / 8 unit')
        ->assertSeeText('Hasil borongan: Rp 75.000')
        ->assertSeeText('3,8% hadir')
        ->assertDontSeeText('Kehadiran Bulan Ini');
});

it('counts standalone Bali Borongan production as half-day for performance but not on attendance', function () {
    $this->travelTo('2026-11-03 08:15:00');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance', 'performance'],
    ]);
    $employee = Employee::factory()->create([
        'full_name' => 'Borongan Production Worker',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-02',
        'daily_wage' => null,
    ]);
    PieceworkItem::create([
        'employee_id' => $employee->id,
        'product_name' => 'Handmade product',
        'quantity' => 2,
        'unit_price' => 25000,
        'line_total' => 50000,
        'target_deadline' => '2026-11-10',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.performance.index', ['month' => '2026-11']))
        ->assertSeeText('0,0% hadir');

    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText($employee->full_name)
        ->assertSee('aria-label="Hadir Borongan Production Worker sesi 1"', false);
});
