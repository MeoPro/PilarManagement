<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeOvertime;
use App\Models\PayrollPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deducts cashbond automatically from a salary payment', function () {
    $this->travelTo('2026-11-16 10:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'Karyawan Payroll',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
        'cashbond' => 200000,
        'started_at' => '2026-11-02',
    ]);

    EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-02',
        'session' => 'morning',
        'work_type' => 'Harian',
        'daily_pay_amount' => 50000,
        'attended_at' => '2026-11-02 08:00:00',
    ]);
    EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-02',
        'session' => 'afternoon',
        'work_type' => 'Borongan',
        'piecework_amount' => 50000,
        'attended_at' => '2026-11-02 13:00:00',
    ]);
    EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-03',
        'session' => 'morning',
        'work_type' => 'Harian',
        'daily_pay_amount' => 50000,
        'attended_at' => '2026-11-03 08:00:00',
    ]);

    $payrollPage = $this->actingAs($admin)
        ->get(route('admin.payroll.index', ['period' => '2026-11']))
        ->assertSeeText('Rp 150.000')
        ->assertSeeText('Rp 200.000');
    expect(substr_count($payrollPage->getContent(), '<td>Karyawan Payroll</td>'))->toBe(1);

    $this->post(route('admin.payroll.pay', $employee), [
        'period' => '2026-11',
        'salary_payment' => '150,000',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Gaji sebesar Rp 150.000 berhasil dicatat. Cashbond dipotong otomatis Rp 150.000; gaji bersih yang diterima Rp 0.');

    expect((float) $employee->fresh()->cashbond)->toBe(50000.0);
    $this->assertDatabaseHas('payroll_payments', [
        'employee_id' => $employee->id,
        'period' => '2026-11',
        'daily_pay_total' => 100000,
        'piecework_pay_total' => 50000,
        'salary_paid' => 150000,
        'cashbond_paid' => 150000,
        'cashbond_balance_after' => 50000,
        'paid_by_id' => $admin->id,
    ]);

    $this->get(route('admin.payroll.index', ['period' => '2026-11']))
        ->assertSeeText('Rp 150.000')
        ->assertSeeText('Rp 50.000');

    expect((float) PayrollPayment::where('employee_id', $employee->id)->sum('salary_paid'))->toBe(150000.0);
});

it('rejects cashbond payments above the current balance without recording a salary payment', function () {
    $this->travelTo('2026-11-16 10:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'cashbond' => 100000,
        'started_at' => '2026-11-02',
    ]);

    EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-02',
        'session' => 'morning',
        'work_type' => 'Harian',
        'attended_at' => '2026-11-02 08:00:00',
    ]);

    $this->actingAs($admin)
        ->from(route('admin.payroll.index', ['period' => '2026-11']))
        ->post(route('admin.payroll.pay', $employee), [
            'period' => '2026-11',
            'cashbond_payment' => '150,000',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('cashbond_payment');

    expect((float) $employee->fresh()->cashbond)->toBe(100000.0);
    $this->assertDatabaseCount('payroll_payments', 0);
});

it('shows and pays Harian and Borongan salary balances independently', function () {
    $this->travelTo('2026-11-16 10:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'daily_wage' => 100000,
        'started_at' => '2026-11-02',
    ]);

    EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-02',
        'session' => 'morning',
        'work_type' => 'Harian',
        'daily_pay_amount' => 50000,
        'attended_at' => '2026-11-02 08:00:00',
    ]);
    EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-02',
        'session' => 'afternoon',
        'work_type' => 'Borongan',
        'piecework_amount' => 200000,
        'attended_at' => '2026-11-02 12:00:00',
    ]);
    EmployeeOvertime::create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-11-02',
        'overtime_hours' => 2.5,
        'hourly_rate' => 20000,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.payroll.index', ['period' => '2026-11', 'salary_type' => 'Harian']))
        ->assertSee('<th>Gaji Harian</th>', false)
        ->assertSee('<th>Lembur</th>', false)
        ->assertDontSee('<th>Gaji Borongan</th>', false)
        ->assertSee('Rp 50.000');

    $this->post(route('admin.payroll.pay', $employee), [
        'period' => '2026-11',
        'salary_type' => 'Harian',
        'salary_payment' => 40000,
    ])->assertRedirect();

    $this->assertDatabaseHas('payroll_payments', [
        'employee_id' => $employee->id,
        'daily_salary_paid' => 40000,
        'overtime_pay_total' => 50000,
        'piecework_salary_paid' => 0,
    ]);
    $this->get(route('admin.payroll.index', ['period' => '2026-11', 'salary_type' => 'Harian']))
        ->assertSeeText('Rp 60.000');

    $this->post(route('admin.payroll.pay', $employee), [
        'period' => '2026-11',
        'salary_type' => 'Harian',
        'salary_payment' => 70000,
    ])->assertSessionHasErrors('salary_payment');

    $this->post(route('admin.payroll.pay', $employee), [
        'period' => '2026-11',
        'salary_type' => 'Harian',
        'salary_payment' => '',
        'pay_all' => 1,
    ])->assertRedirect();

    $this->assertDatabaseHas('payroll_payments', [
        'employee_id' => $employee->id,
        'daily_salary_paid' => 60000,
        'piecework_salary_paid' => 0,
    ]);

    $this->get(route('admin.payroll.index', ['period' => '2026-11', 'salary_type' => 'Borongan']))
        ->assertSee('<th>Gaji Borongan</th>', false)
        ->assertDontSee('<th>Gaji Harian</th>', false)
        ->assertSee('Rp 200.000');

    $this->post(route('admin.payroll.pay', $employee), [
        'period' => '2026-11',
        'salary_type' => 'Borongan',
    ])->assertRedirect();

    $this->assertDatabaseHas('payroll_payments', [
        'employee_id' => $employee->id,
        'daily_salary_paid' => 0,
        'piecework_salary_paid' => 200000,
    ]);
});

it('excludes Izin and Alpa sessions from payroll even if stale pay values remain', function () {
    $this->travelTo('2026-11-16 10:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'daily_wage' => 100000,
        'started_at' => '2026-11-02',
    ]);

    foreach ([
        ['2026-11-02', 'Hadir', 50000],
        ['2026-11-03', 'Izin', 70000],
        ['2026-11-04', 'Alpa', 60000],
    ] as [$date, $status, $payAmount]) {
        EmployeeAttendance::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'session' => 'morning',
            'work_type' => 'Harian',
            'attendance_status' => $status,
            'daily_pay_amount' => $payAmount,
            'attended_at' => "{$date} 08:00:00",
        ]);
    }

    $this->actingAs($admin)
        ->get(route('admin.payroll.index', ['period' => '2026-11', 'salary_type' => 'Harian']))
        ->assertSeeText('Rp 50.000')
        ->assertDontSeeText('Rp 180.000');

    $this->post(route('admin.payroll.pay', $employee), [
        'period' => '2026-11',
        'salary_type' => 'Harian',
    ])->assertRedirect();

    $this->assertDatabaseHas('payroll_payments', [
        'employee_id' => $employee->id,
        'daily_pay_total' => 50000,
        'salary_paid' => 50000,
    ]);
});
