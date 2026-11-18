<?php

use App\Models\CashbondAdvance;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Material;
use App\Models\Order;
use App\Models\PayrollPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('revenue uses receipt dates and separates historic balances from dated cash flow', function () {
    $this->travelTo('2026-11-18 10:00:00');
    $admin = User::factory()->admin()->create(['accessible_menus' => ['revenue']]);
    $order = Order::create([
        'order_id' => 'REVENUE-DATED-ORDER',
        'guest_name' => 'Revenue Guest',
        'total_order' => 1000000,
        'paid_amount' => 550000,
        'opening_paid_amount' => 300000,
        'status' => 'Proses Produksi',
    ]);
    $order->payments()->createMany([
        [
            'amount' => 200000,
            'paid_at' => '2026-11-10',
            'note' => 'Cicilan periode',
        ],
        [
            'amount' => 50000,
            'paid_at' => '2026-10-31',
            'note' => 'Cicilan di luar rentang',
        ],
    ]);
    $previousMonthOrder = Order::create([
        'order_id' => 'REVENUE-PREVIOUS-MONTH-ORDER',
        'guest_name' => 'Previous Month Guest',
        'total_order' => 300000,
        'status' => 'None',
    ]);
    $previousMonthOrder->forceFill(['created_at' => '2026-10-31 23:59:00'])->save();
    Order::create([
        'order_id' => 'REVENUE-CANCELLED-ORDER',
        'guest_name' => 'Cancelled Guest',
        'total_order' => 500000,
        'status' => 'Cancel',
    ]);
    $employee = Employee::factory()->create([
        'full_name' => 'Finance Bali Employee',
        'business_unit' => User::UNIT_BALI,
    ]);
    PayrollPayment::factory()->for($employee)->create([
        'period' => '2026-11',
        'salary_paid' => 500000,
        'cashbond_paid' => 100000,
        'paid_at' => '2026-11-12 10:00:00',
    ]);
    PayrollPayment::factory()->for($employee)->create([
        'period' => '2026-11',
        'salary_paid' => 0,
        'cashbond_paid' => 20000,
        'paid_at' => '2026-11-13 10:00:00',
    ]);
    Expense::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'description' => 'Biaya operasional periode',
        'quantity' => 1,
        'unit_price' => 100000,
        'total' => 100000,
        'occurred_at' => '2026-11-14',
        'created_by_id' => $admin->id,
    ]);
    Expense::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'description' => 'Biaya di luar rentang',
        'occurred_at' => '2026-10-31',
        'created_by_id' => $admin->id,
    ]);
    CashbondAdvance::create([
        'employee_id' => $employee->id,
        'recorded_by_id' => $admin->id,
        'advance_date' => '2026-11-15',
        'salary_earned' => 100000,
        'amount' => 50000,
        'balance_after' => 50000,
    ]);

    $response = $this->actingAs($admin)->get(route('revenue.index', [
        'from' => '2026-11-01',
        'to' => '2026-11-30',
    ]));

    $response->assertSeeText('REVENUE-DATED-ORDER')
        ->assertSeeText('Finance Bali Employee')
        ->assertSeeText('Biaya operasional periode')
        ->assertSeeText('Saldo DP lama tanpa tanggal')
        ->assertSeeText('Total order bulan ini')
        ->assertDontSeeText('Omzet pembayaran order')
        ->assertDontSeeText('Cicilan di luar rentang')
        ->assertDontSeeText('Biaya di luar rentang')
        ->assertViewHas('orderRevenue', 200000.0)
        ->assertViewHas('monthlyOrderTotal', 1000000.0)
        ->assertViewHas('cashbondRepaymentTotal', 20000.0)
        ->assertViewHas('totalIncoming', 220000.0)
        ->assertViewHas('expenseTotal', 100000.0)
        ->assertViewHas('salaryTotal', 400000.0)
        ->assertViewHas('cashbondAdvanceTotal', 50000.0)
        ->assertViewHas('totalOutgoing', 550000.0)
        ->assertViewHas('netCashFlow', -330000.0)
        ->assertViewHas('openingPaidAmount', 300000.0);

    $this->actingAs($admin)
        ->get(route('revenue.index', [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]))
        ->assertViewHas('monthlyOrderTotal', 1000000.0);
});

test('a payment received on the selected final date is included in order revenue', function () {
    $this->travelTo('2026-11-18 10:00:00');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['orders', 'revenue'],
    ]);
    $order = Order::create([
        'order_id' => 'REVENUE-CURRENT-DAY-PAYMENT',
        'guest_name' => 'Current Day Payment Guest',
        'total_order' => 500000,
        'status' => 'Proses Produksi',
    ]);

    $this->actingAs($admin)
        ->post(route('orders.payments.store', $order), [
            'amount' => '100,000',
            'paid_at' => '2026-11-18',
            'note' => 'Cicilan hari ini',
        ])
        ->assertRedirect(route('orders.show', $order));

    $this->get(route('revenue.index', [
        'from' => '2026-11-01',
        'to' => '2026-11-18',
    ]))
        ->assertSeeText('REVENUE-CURRENT-DAY-PAYMENT')
        ->assertSeeText('Cicilan hari ini')
        ->assertViewHas('orderRevenue', 100000.0)
        ->assertViewHas('totalIncoming', 100000.0);
});

test('revenue report only shows transactions from the active business unit', function () {
    $this->travelTo('2026-11-18 10:00:00');
    $factoryAdmin = User::factory()->factoryAdmin()->create(['accessible_menus' => ['revenue']]);
    Expense::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'description' => 'Private Bali expense',
        'occurred_at' => '2026-11-18',
    ]);
    Expense::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'description' => 'Factory expense',
        'occurred_at' => '2026-11-18',
    ]);
    $baliMaterial = Material::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'name' => 'Private Bali Material',
    ]);
    $baliMaterial->transactions()->create([
        'type' => 'in',
        'quantity' => 1,
        'unit_price' => 100,
        'occurred_at' => '2026-11-18 10:00:00',
    ]);
    $factoryMaterial = Material::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'name' => 'Factory Material Purchase',
    ]);
    $factoryMaterial->transactions()->create([
        'type' => 'in',
        'quantity' => 2,
        'unit_price' => 400,
        'occurred_at' => '2026-11-18 11:00:00',
    ]);
    $order = Order::create([
        'order_id' => 'PRIVATE-BALI-INCOME',
        'guest_name' => 'Private Guest',
        'total_order' => 1000,
        'status' => 'None',
    ]);
    $order->payments()->create([
        'amount' => 100,
        'paid_at' => '2026-11-18',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('revenue.index', [
            'from' => '2026-11-01',
            'to' => '2026-11-30',
        ]))
        ->assertSeeText('Factory expense')
        ->assertSeeText('Factory Material Purchase')
        ->assertDontSeeText('Private Bali expense')
        ->assertDontSeeText('Private Bali Material')
        ->assertDontSeeText('PRIVATE-BALI-INCOME')
        ->assertViewHas('monthlyOrderTotal', 0.0);
});

test('revenue page requires its assigned menu permission', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create(['accessible_menus' => ['expenses']]);

    $this->actingAs($factoryAdmin)
        ->get(route('revenue.index'))
        ->assertForbidden();
});

test('revenue date range rejects an end date before the start date', function () {
    $admin = User::factory()->admin()->create(['accessible_menus' => ['revenue']]);

    $this->actingAs($admin)
        ->get(route('revenue.index', [
            'from' => '2026-11-20',
            'to' => '2026-11-01',
        ]))
        ->assertSessionHasErrors('to');
});
