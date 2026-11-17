<?php

use App\Models\Guest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows colored order totals and ranks guests by earliest unpaid completed order', function () {
    $this->travelTo('2026-11-16 10:00:00');

    $oldestDebtor = Guest::create(['name' => 'Tamu Hutang Lama']);
    $newerDebtor = Guest::create(['name' => 'Tamu Hutang Baru']);
    $paidGuest = Guest::create(['name' => 'Tamu Lunas']);
    $pendingGuest = Guest::create(['name' => 'Tamu Pending']);

    $oldestDebtOrder = Order::create([
        'order_id' => 'DEBT-OLDEST',
        'guest_id' => $oldestDebtor->id,
        'guest_name' => $oldestDebtor->name,
        'total_order' => 500000,
        'paid_amount' => 100000,
        'status' => 'Selesai',
    ]);
    $oldestDebtOrder->forceFill(['created_at' => '2026-10-01 10:00:00'])->save();

    Order::create([
        'order_id' => 'DEBT-NEWER',
        'guest_id' => $newerDebtor->id,
        'guest_name' => $newerDebtor->name,
        'total_order' => 300000,
        'paid_amount' => 0,
        'status' => 'Selesai',
    ]);
    Order::create([
        'order_id' => 'DEBT-PAID',
        'guest_id' => $paidGuest->id,
        'guest_name' => $paidGuest->name,
        'total_order' => 200000,
        'paid_amount' => 200000,
        'status' => 'Selesai',
    ]);
    Order::create([
        'order_id' => 'DEBT-PENDING',
        'guest_id' => $pendingGuest->id,
        'guest_name' => $pendingGuest->name,
        'total_order' => 100000,
        'paid_amount' => 0,
        'status' => 'None',
    ]);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('admin.index'))
        ->assertSeeText('Total Pendapatan')
        ->assertSeeText('Rp 1,100,000')
        ->assertSeeText('Total Orders')
        ->assertSeeText('Pending')
        ->assertSeeText('Selesai')
        ->assertSeeTextInOrder(['Tamu Hutang Lama', 'Tamu Hutang Baru'])
        ->assertDontSeeText('Tamu Lunas')
        ->assertDontSeeText('Tamu Pending');
});
