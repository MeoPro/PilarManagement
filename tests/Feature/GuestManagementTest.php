<?php

use App\Models\Guest;
use App\Models\GuestPhone;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('editing a guest synchronizes name and address while preserving historical order phone', function () {
    $guest = Guest::create([
        'name' => 'Old Guest',
        'address' => 'Old Address',
    ]);
    GuestPhone::create([
        'guest_id' => $guest->id,
        'phone' => '08123456789',
    ]);
    $order = Order::create([
        'order_id' => 'GUEST-HISTORY',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'phone' => '08111111111',
        'total_order' => 50000,
        'status' => 'None',
    ]);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('guests.update', $guest), [
            'name' => 'New Guest <script>alert(1)</script>',
            'address' => 'New Address',
        ]);

    $response->assertRedirect(route('guests.show', $guest))
        ->assertSessionHas('success', 'Data tamu berhasil diperbarui.');
    $this->assertDatabaseHas('guests', [
        'id' => $guest->id,
        'name' => 'New Guest <script>alert(1)</script>',
        'address' => 'New Address',
    ]);
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'guest_name' => 'New Guest <script>alert(1)</script>',
        'phone' => '08111111111',
    ]);

    $this->get(route('guests.show', $guest))
        ->assertSee('Data tamu berhasil diperbarui.')
        ->assertSee('New Guest &lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('New Address');
});

test('factory admin cannot edit guest information', function () {
    $guest = Guest::create(['name' => 'Private Guest']);

    $this->actingAs(User::factory()->factoryAdmin()->create())
        ->get(route('guests.edit', $guest))
        ->assertForbidden();
});

test('guest edit updates the guest phone list alongside name and address', function () {
    $guest = Guest::create([
        'name' => 'Phone Guest',
        'address' => 'Old Address',
    ]);
    GuestPhone::create(['guest_id' => $guest->id, 'phone' => '08111111111']);
    GuestPhone::create(['guest_id' => $guest->id, 'phone' => '08222222222']);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('guests.edit', $guest))
        ->put(route('guests.update', $guest), [
            'name' => 'Updated Phone Guest',
            'address' => 'New Address',
            'phones' => ['08111111111', '08333333333', '08555555555'],
        ])
        ->assertRedirect(route('guests.show', $guest));

    $this->assertDatabaseMissing('guest_phones', [
        'guest_id' => $guest->id,
        'phone' => '08222222222',
    ]);
    $this->assertDatabaseHas('guest_phones', [
        'guest_id' => $guest->id,
        'phone' => '08111111111',
    ]);
    $this->assertDatabaseHas('guest_phones', [
        'guest_id' => $guest->id,
        'phone' => '08333333333',
    ]);
    $this->assertDatabaseHas('guest_phones', [
        'guest_id' => $guest->id,
        'phone' => '08555555555',
    ]);
    $this->assertDatabaseHas('guests', [
        'id' => $guest->id,
        'name' => 'Updated Phone Guest',
        'address' => 'New Address',
    ]);
});

test('guest edit form shows saved phone fields and an add-number button', function () {
    $guest = Guest::create(['name' => 'Phone Form Guest']);
    GuestPhone::create(['guest_id' => $guest->id, 'phone' => '08111111111']);
    GuestPhone::create(['guest_id' => $guest->id, 'phone' => '08222222222']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('guests.edit', $guest))
        ->assertSee('name="phones[]"', false)
        ->assertSee('value="08111111111"', false)
        ->assertSee('value="08222222222"', false)
        ->assertSee('id="add-guest-phone"', false)
        ->assertSee('+ Tambah Nomor');
});

test('guest edit requires a valid name', function () {
    $guest = Guest::create(['name' => 'Existing Guest']);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('guests.edit', $guest))
        ->put(route('guests.update', $guest), [
            'name' => '',
            'address' => 'Address',
        ])
        ->assertSessionHasErrors('name');

    $this->assertDatabaseHas('guests', [
        'id' => $guest->id,
        'name' => 'Existing Guest',
    ]);
});

test('guest list and profile show the total number of recorded orders', function () {
    $guest = Guest::create(['name' => 'Frequent Guest']);

    foreach (['None', 'Selesai', 'Cancel'] as $index => $status) {
        Order::create([
            'order_id' => 'GUEST-COUNT-'.$index,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'total_order' => 10000,
            'status' => $status,
        ]);
    }

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('guests.index'))
        ->assertViewHas('guests', fn ($guests): bool => $guests
            ->firstWhere('id', $guest->id)
            ->orders_count === 3);

    $this->get(route('guests.show', $guest))
        ->assertSee('Jumlah order:</strong> 3 kali', false);
});

test('active orders prevent deleting a guest and keep the guest record intact', function () {
    $guest = Guest::create(['name' => 'Guest With Active Order']);
    $order = Order::create([
        'order_id' => 'ACTIVE-GUEST-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 10000,
        'status' => 'Proses Produksi',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('guests.show', $guest))
        ->delete(route('guests.destroy', $guest))
        ->assertRedirect(route('guests.show', $guest))
        ->assertSessionHas('error', 'Tamu tidak dapat dihapus karena masih memiliki order yang sedang berjalan.');

    $this->assertModelExists($guest);
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'guest_id' => $guest->id,
        'status' => 'Proses Produksi',
    ]);
});

test('guest delete action is hidden while an active order exists', function () {
    $guest = Guest::create(['name' => 'Guest Delete Visibility']);
    Order::create([
        'order_id' => 'GUEST-DELETE-VISIBILITY',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 10000,
        'status' => 'Finishing',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('guests.index'))
        ->assertDontSee('aria-label="Hapus tamu Guest Delete Visibility"', false);

    $this->get(route('guests.show', $guest))
        ->assertDontSee('aria-label="Hapus tamu Guest Delete Visibility"', false);
});

test('guest without active orders can be deleted without removing historical orders', function () {
    $guest = Guest::create(['name' => 'Guest With Only Finished Orders']);
    $admin = User::factory()->admin()->create();
    $completedOrder = Order::create([
        'order_id' => 'COMPLETED-GUEST-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 10000,
        'status' => 'Selesai',
    ]);
    $cancelledOrder = Order::create([
        'order_id' => 'CANCELLED-GUEST-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 5000,
        'status' => 'Cancel',
    ]);

    $this->actingAs($admin)
        ->get(route('guests.index'))
        ->assertSee('aria-label="Hapus tamu Guest With Only Finished Orders"', false);

    $this->get(route('guests.show', $guest))
        ->assertSee('aria-label="Hapus tamu Guest With Only Finished Orders"', false);

    $this->delete(route('guests.destroy', $guest))
        ->assertRedirect(route('guests.index'))
        ->assertSessionHas('success', 'Data tamu berhasil dihapus.');

    $this->assertDatabaseMissing('guests', ['id' => $guest->id]);
    $this->assertDatabaseHas('orders', [
        'id' => $completedOrder->id,
        'guest_id' => null,
        'guest_name' => 'Guest With Only Finished Orders',
    ]);
    $this->assertDatabaseHas('orders', [
        'id' => $cancelledOrder->id,
        'guest_id' => null,
    ]);
});
