<?php

use App\Models\CashbondAdvance;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PayrollPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('limits total cashbond to twice earned salary and deducts salary payments automatically', function () {
    $this->travelTo('2026-11-16 10:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'daily_wage' => 800000,
        'started_at' => '2026-11-02',
    ]);

    foreach (['2026-11-02', '2026-11-03'] as $date) {
        EmployeeAttendance::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'session' => 'morning',
            'work_type' => 'Harian',
            'attendance_status' => 'Hadir',
            'daily_pay_amount' => 400000,
            'attended_at' => "{$date} 08:00:00",
        ]);
    }
    EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-04',
        'session' => 'morning',
        'work_type' => 'Borongan',
        'attendance_status' => 'Hadir',
        'daily_pay_amount' => 900000,
        'attended_at' => '2026-11-04 08:00:00',
    ]);
    EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-05',
        'session' => 'morning',
        'work_type' => 'Harian',
        'attendance_status' => 'Hadir',
        'piecework_amount' => 700000,
        'attended_at' => '2026-11-05 08:00:00',
    ]);

    $this->actingAs($admin)
        ->from(route('admin.cashbond.index'))
        ->post(route('admin.cashbond.store'), [
            'employee_id' => $employee->id,
            'advance_date' => '2026-11-16',
            'amount' => '1,600,001',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount');

    expect((float) $employee->fresh()->cashbond)->toBe(0.0);
    $this->assertDatabaseCount('cashbond_advances', 0);

    $this->post(route('admin.cashbond.store'), [
        'employee_id' => $employee->id,
        'advance_date' => '2026-11-16',
        'amount' => '1,600,000',
    ])->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('cashbond_advances', [
        'employee_id' => $employee->id,
        'recorded_by_id' => $admin->id,
        'advance_date' => '2026-11-16 00:00:00',
        'salary_earned' => 800000,
        'amount' => 1600000,
        'balance_after' => 1600000,
    ]);
    expect((float) $employee->fresh()->cashbond)->toBe(1600000.0);

    $this->get(route('admin.cashbond.index'))
        ->assertSeeText($employee->full_name)
        ->assertSeeText('Rp 1.600.000');

    $this->post(route('admin.payroll.pay', $employee), [
        'period' => '2026-11',
        'salary_type' => 'Harian',
        'salary_payment' => 800000,
    ])->assertRedirect()
        ->assertSessionHas('success', 'Gaji sebesar Rp 800.000 berhasil dicatat. Cashbond dipotong otomatis Rp 800.000; gaji bersih yang diterima Rp 0.');

    expect((float) $employee->fresh()->cashbond)->toBe(800000.0);
    $this->assertDatabaseHas('payroll_payments', [
        'employee_id' => $employee->id,
        'daily_salary_paid' => 800000,
        'salary_paid' => 800000,
        'cashbond_paid' => 800000,
        'cashbond_balance_after' => 800000,
    ]);
    expect(PayrollPayment::query()->where('employee_id', $employee->id)->count())->toBe(1);
    expect(CashbondAdvance::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('does not expose or allow cashbond advances for employees outside the admin unit', function () {
    $this->travelTo('2026-11-16 10:00:00');
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'started_at' => '2026-11-02',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.cashbond.store'), [
            'employee_id' => $employee->id,
            'advance_date' => '2026-11-16',
            'amount' => 1000,
        ])
        ->assertNotFound();

    $this->assertDatabaseCount('cashbond_advances', 0);
    expect((float) $employee->fresh()->cashbond)->toBe(0.0);
});
