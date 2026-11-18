<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a dated installment increases the paid total without changing the legacy opening balance', function () {
    $this->travelTo('2026-11-18 10:00:00');
    $admin = User::factory()->admin()->create(['accessible_menus' => ['orders']]);
    $order = Order::create([
        'order_id' => 'ORDER-INSTALLMENT',
        'guest_name' => 'Installment Guest',
        'total_order' => 1000000,
        'paid_amount' => 200000,
        'opening_paid_amount' => 200000,
        'status' => 'None',
    ]);

    $this->actingAs($admin)
        ->post(route('orders.payments.store', $order), [
            'amount' => '250,000',
            'paid_at' => '2026-11-17',
            'note' => 'Cicilan kedua',
        ])
        ->assertRedirect(route('orders.show', $order))
        ->assertSessionHas('success', 'Pembayaran berhasil dicatat.');

    $payment = $order->payments()
        ->where('amount', 250000)
        ->whereDate('paid_at', '2026-11-17')
        ->where('note', 'Cicilan kedua')
        ->where('received_by_id', $admin->id)
        ->exists();
    expect($payment)->toBeTrue();
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'payment_received',
    ]);
    expect((float) $order->fresh()->paid_amount)->toBe(450000.0)
        ->and((float) $order->fresh()->opening_paid_amount)->toBe(200000.0)
        ->and($order->fresh()->amount_remaining)->toBe(550000.0);
});

test('an installment cannot exceed the remaining balance', function () {
    $admin = User::factory()->admin()->create(['accessible_menus' => ['orders']]);
    $order = Order::create([
        'order_id' => 'ORDER-INSTALLMENT-LIMIT',
        'guest_name' => 'Installment Limit Guest',
        'total_order' => 1000000,
        'paid_amount' => 800000,
        'opening_paid_amount' => 800000,
        'status' => 'None',
    ]);

    $this->actingAs($admin)
        ->post(route('orders.payments.store', $order), [
            'amount' => 200001,
            'paid_at' => '2026-11-18',
        ])
        ->assertSessionHasErrors('amount');

    $this->assertDatabaseCount('order_payments', 0);
    expect((float) $order->fresh()->paid_amount)->toBe(800000.0);
});

test('a payment date cannot be in the future', function () {
    $this->travelTo('2026-11-18 10:00:00');
    $admin = User::factory()->admin()->create(['accessible_menus' => ['orders']]);
    $order = Order::create([
        'order_id' => 'ORDER-FUTURE-PAYMENT',
        'guest_name' => 'Future Payment Guest',
        'total_order' => 1000,
        'status' => 'None',
    ]);

    $this->actingAs($admin)
        ->post(route('orders.payments.store', $order), [
            'amount' => 100,
            'paid_at' => '2026-11-19',
        ])
        ->assertSessionHasErrors('paid_at');

    $this->assertDatabaseCount('order_payments', 0);
});

test('order payment input is wired to the live currency formatter', function () {
    $admin = User::factory()->admin()->create(['accessible_menus' => ['orders']]);
    $order = Order::create([
        'order_id' => 'ORDER-FORMATTED-PAYMENT',
        'guest_name' => 'Formatted Payment Guest',
        'total_order' => 1000000,
        'status' => 'Proses Produksi',
    ]);

    $this->actingAs($admin)
        ->get(route('orders.show', $order))
        ->assertSee('id="payment-amount"', false)
        ->assertSee('data-money-input', false)
        ->assertSee('placeholder="Contoh: 100,000"', false);
});

test('factory admins cannot record payments for Bali orders', function () {
    $factoryAdmin = User::factory()->factoryAdmin()->create(['accessible_menus' => ['orders']]);
    $order = Order::create([
        'order_id' => 'ORDER-FACTORY-PAYMENT',
        'guest_name' => 'Factory Payment Guest',
        'total_order' => 1000,
        'status' => 'None',
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('orders.payments.store', $order), [
            'amount' => 100,
            'paid_at' => today()->toDateString(),
        ])
        ->assertForbidden();

    $this->assertDatabaseCount('order_payments', 0);
});
