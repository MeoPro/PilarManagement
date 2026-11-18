<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Guest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('warna baru dapat ditambahkan ke daftar warna', function () {
    $response = $this->postJson(route('colors.store'), [
        'name' => 'Natural',
    ]);

    $response->assertOk()
        ->assertJsonPath('color.name', 'Natural');

    $this->assertDatabaseHas('colors', [
        'name' => 'Natural',
    ]);
});

test('warna yang sudah terdaftar tidak dapat ditambahkan lagi', function () {
    Color::create(['name' => 'Natural']);

    $response = $this->postJson(route('colors.store'), [
        'name' => 'Natural',
    ]);

    $response->assertInvalid([
        'name' => 'Warna ini sudah ditambahkan.',
    ]);

    expect(Color::count())->toBe(1);
});

test('form order menampilkan warna katalog dan warna lama pada edit', function () {
    Color::create(['name' => 'Olive']);

    $createResponse = $this->get(route('orders.create'));
    $createResponse->assertSee('Olive');

    $guest = Guest::create(['name' => 'Guest']);
    $order = Order::create([
        'order_id' => 'ORD-COLOR',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest',
        'total_order' => 100,
        'status' => 'None',
    ]);

    $order->items()->create([
        'product' => 'Legacy Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'None',
        'color' => 'Legacy Pearl',
    ]);

    $editResponse = $this->get(route('orders.edit', $order));
    $editResponse->assertSee('Legacy Pearl')
        ->assertDontSee('<option value="Service"', false);
});

test('form order suggests products and selectable categories and preselects the guest source', function () {
    $guest = Guest::create(['name' => 'Historical Product Guest']);
    $category = Category::factory()->create(['name' => 'Historical Category']);
    Order::create([
        'order_id' => 'HISTORICAL-PRODUCT-ORDER-OLD',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Website',
        'total_order' => 100,
        'status' => 'None',
    ]);
    $order = Order::create([
        'order_id' => 'HISTORICAL-PRODUCT-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Instagram',
        'total_order' => 100,
        'status' => 'None',
    ]);
    $order->items()->create([
        'product' => 'Historical Dining Table',
        'category_id' => $category->id,
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'None',
    ]);

    $this->get(route('orders.create'))
        ->assertSee('<datalist id="product-options">', false)
        ->assertSee('<option value="Historical Dining Table">', false)
        ->assertSee('<datalist id="category-options">', false)
        ->assertSee('<option value="Historical Category"></option>', false)
        ->assertSee('list="category-options"', false)
        ->assertSee('"guest_source":"Instagram"', false)
        ->assertSee('value="__custom"', false);
});

test('order status tabs return only filtered results for asynchronous requests', function () {
    Order::create([
        'order_id' => 'AJAX-PENDING-ORDER',
        'guest_name' => 'Pending Guest',
        'total_order' => 100,
        'status' => 'None',
    ]);
    Order::create([
        'order_id' => 'AJAX-PROCESSING-ORDER',
        'guest_name' => 'Processing Guest',
        'total_order' => 100,
        'status' => 'Proses Produksi',
    ]);

    $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->get(route('orders.index', ['status' => 'None']))
        ->assertSeeText('AJAX-PENDING-ORDER')
        ->assertDontSeeText('AJAX-PROCESSING-ORDER')
        ->assertSee('id="order-results"', false)
        ->assertDontSee('<!doctype html>', false);
});

test('order summary counts this month and archives paid completed orders under the completed tab', function () {
    $this->travelTo('2026-11-18 10:00:00');
    $admin = User::factory()->admin()->create(['accessible_menus' => ['orders']]);
    $archivedOrder = Order::create([
        'order_id' => 'ORDER-ARCHIVED-PAID',
        'guest_name' => 'Archived Paid Guest',
        'total_order' => 300000,
        'paid_amount' => 300000,
        'status' => 'Selesai',
    ]);
    $archivedOrder->forceFill(['created_at' => '2026-10-31 23:59:00'])->save();
    $unpaidCompletedOrder = Order::create([
        'order_id' => 'ORDER-OLD-UNPAID',
        'guest_name' => 'Old Unpaid Guest',
        'total_order' => 400000,
        'paid_amount' => 100000,
        'status' => 'Selesai',
    ]);
    $unpaidCompletedOrder->forceFill(['created_at' => '2026-10-31 23:59:00'])->save();
    $previousMonthActiveOrder = Order::create([
        'order_id' => 'ORDER-OLD-ACTIVE',
        'guest_name' => 'Old Active Guest',
        'total_order' => 100000,
        'status' => 'Proses Produksi',
    ]);
    $previousMonthActiveOrder->forceFill(['created_at' => '2026-10-31 23:59:00'])->save();
    $currentMonthCompletedOrder = Order::create([
        'order_id' => 'ORDER-CURRENT-PAID',
        'guest_name' => 'Current Paid Guest',
        'total_order' => 200000,
        'paid_amount' => 200000,
        'status' => 'Selesai',
    ]);
    $currentMonthActiveOrder = Order::create([
        'order_id' => 'ORDER-CURRENT-ACTIVE',
        'guest_name' => 'Current Active Guest',
        'total_order' => 100000,
        'status' => 'None',
    ]);

    $response = $this->actingAs($admin)
        ->get(route('orders.index'));

    $response
        ->assertSeeText('Total Order Bulan Ini')
        ->assertSeeText($currentMonthCompletedOrder->order_id)
        ->assertSeeText($currentMonthActiveOrder->order_id)
        ->assertSeeText($unpaidCompletedOrder->order_id)
        ->assertSeeText($previousMonthActiveOrder->order_id)
        ->assertDontSeeText($archivedOrder->order_id);

    $content = $response->getContent();
    expect(preg_match('/<span>Total Order Bulan Ini<\/span>\s*<strong>2<\/strong>/', $content))->toBe(1)
        ->and(preg_match('/class="order-status-dot"><\/span>\s*Semua\s*<span class="order-status-count">4<\/span>/', $content))->toBe(1);

    $this->get(route('orders.index', ['status' => 'Selesai']))
        ->assertSeeText($archivedOrder->order_id)
        ->assertSeeText($unpaidCompletedOrder->order_id)
        ->assertSeeText($currentMonthCompletedOrder->order_id);
});

test('daftar tamu menampilkan detail order tersimpan dan mendukung pencarian', function () {
    $guest = Guest::create(['name' => 'Guest Directory Match']);
    $guest->phones()->create(['phone' => '08123456789']);

    Order::create([
        'order_id' => 'ORD-GUEST-001',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest Directory Match',
        'phone' => '08123456789',
        'total_order' => 1250000,
        'status' => 'None',
    ]);

    $otherGuest = Guest::create(['name' => 'Another Guest']);
    $otherGuest->phones()->create(['phone' => '08987654321']);

    Order::create([
        'order_id' => 'ORD-GUEST-002',
        'guest_id' => $otherGuest->id,
        'guest_name' => 'Another Guest',
        'phone' => '08987654321',
        'total_order' => 500000,
        'status' => 'None',
    ]);

    $response = $this->get(route('guests.index', ['search' => 'Guest Directory']));

    $response->assertSee('Guest Directory Match')
        ->assertSee('08123456789')
        ->assertSee('ORD-GUEST-001')
        ->assertSee('Rp 1.250.000')
        ->assertDontSee('ORD-GUEST-002');
});

test('guest list shows completed debt separately from all unpaid non-cancelled orders', function () {
    $guest = Guest::create(['name' => 'Guest With Debt']);

    Order::create([
        'order_id' => 'ORD-DEBT-COMPLETE',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 500000,
        'paid_amount' => 200000,
        'status' => 'Selesai',
    ]);
    Order::create([
        'order_id' => 'ORD-DEBT-IN-PROGRESS',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 400000,
        'paid_amount' => 0,
        'status' => 'Proses Produksi',
    ]);

    $response = $this->get(route('guests.index'));

    $response->assertSee('Hutang')
        ->assertSee('Sisa Pembayaran')
        ->assertSee('Rp 300.000')
        ->assertSee('Rp 700.000');

    expect(Order::where('order_id', 'ORD-DEBT-COMPLETE')->firstOrFail()->is_debt)->toBeTrue()
        ->and(Order::where('order_id', 'ORD-DEBT-IN-PROGRESS')->firstOrFail()->is_debt)->toBeFalse();
});

test('guest list payment balance excludes cancelled orders and counts partial payments', function () {
    $guest = Guest::create(['name' => 'Payment Balance Guest']);
    foreach ([
        ['GUEST-BALANCE-ACTIVE', 'Proses Produksi', 100000, 25000],
        ['GUEST-BALANCE-CANCELLED', 'Cancel', 50000, 0],
        ['GUEST-BALANCE-PAID', 'Selesai', 30000, 30000],
    ] as [$orderId, $status, $total, $paid]) {
        Order::create([
            'order_id' => $orderId,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'total_order' => $total,
            'paid_amount' => $paid,
            'status' => $status,
        ]);
    }

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('guests.index'))
        ->assertSee('Rp 75.000');
});

test('deadline order harus lebih dari hari ini', function () {
    $guest = Guest::create(['name' => 'Deadline Guest']);
    $order = Order::create([
        'order_id' => 'ORD-DEADLINE-VALIDATION',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'total_order' => 100,
        'deadline' => today()->addDays(10)->toDateString(),
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Deadline Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Ready',
        'factory_status' => 'Ready',
    ]);

    $this->put(route('orders.update', $order), [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'deadline' => today()->toDateString(),
        'items' => [[
            'id' => $item->id,
            'product' => $item->product,
            'qty' => 1,
            'price' => 100,
            'source' => 'Stock',
            'status' => 'Ready',
        ]],
    ])->assertSessionHasErrors('deadline');

    expect($order->fresh()->deadline->toDateString())->toBe(today()->addDays(10)->toDateString());
});

test('percepatan deadline produk produksi menunggu persetujuan PF dan dapat diperbarui', function () {
    $guest = Guest::create(['name' => 'Deadline Request Guest']);
    $currentDeadline = today()->addDays(30)->toDateString();
    $firstRequestedDeadline = today()->addDays(20)->toDateString();
    $updatedRequestedDeadline = today()->addDays(15)->toDateString();
    $order = Order::create([
        'order_id' => 'ORD-DEADLINE-REQUEST',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'total_order' => 100,
        'deadline' => $currentDeadline,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create([
        'product' => 'Deadline Production Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);
    $payload = [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'items' => [[
            'id' => $item->id,
            'product' => $item->product,
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'Produksi',
            'factory_status' => 'Produksi',
        ]],
    ];

    $this->put(route('orders.update', $order), array_merge($payload, [
        'deadline' => $firstRequestedDeadline,
    ]))->assertSessionHasErrors('deadline_change_reason');

    $this->put(route('orders.update', $order), array_merge($payload, [
        'deadline' => $firstRequestedDeadline,
        'deadline_change_reason' => 'Perlu percepatan jadwal kirim.',
    ]))->assertRedirect(route('orders.index'));

    expect($order->fresh()->deadline->toDateString())->toBe($currentDeadline)
        ->and($order->fresh()->pending_deadline->toDateString())->toBe($firstRequestedDeadline)
        ->and($order->fresh()->pending_deadline_reason)->toBe('Perlu percepatan jadwal kirim.');

    $this->put(route('orders.update', $order), array_merge($payload, [
        'deadline' => $updatedRequestedDeadline,
        'deadline_change_reason' => 'Jadwal pengiriman diperbarui.',
    ]))->assertRedirect(route('orders.index'));

    expect($order->fresh()->deadline->toDateString())->toBe($currentDeadline)
        ->and($order->fresh()->pending_deadline->toDateString())->toBe($updatedRequestedDeadline)
        ->and($order->fresh()->pending_deadline_reason)->toBe('Jadwal pengiriman diperbarui.')
        ->and($order->events()->where('type', 'deadline_change_requested')->count())->toBe(2);
});

test('percepatan deadline tanpa produk produksi langsung memperbarui deadline aktif', function () {
    $guest = Guest::create(['name' => 'Direct Deadline Guest']);
    $currentDeadline = today()->addDays(30)->toDateString();
    $newDeadline = today()->addDays(15)->toDateString();
    $order = Order::create([
        'order_id' => 'ORD-DEADLINE-DIRECT',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'total_order' => 100,
        'deadline' => $currentDeadline,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Deadline Ready Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Ready',
        'factory_status' => 'Ready',
    ]);

    $this->put(route('orders.update', $order), [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'deadline' => $newDeadline,
        'items' => [[
            'id' => $item->id,
            'product' => $item->product,
            'qty' => 1,
            'price' => 100,
            'source' => 'Stock',
            'status' => 'Ready',
        ]],
    ])->assertRedirect(route('orders.index'));

    expect($order->fresh()->deadline->toDateString())->toBe($newDeadline)
        ->and($order->fresh()->pending_deadline)->toBeNull();
});

test('deadline internal PF mengurangi satu minggu atau satu bulan sesuai sisa waktu', function () {
    $this->travelTo('2026-01-01 09:00:00');

    $deadlineCases = [
        ['three-weeks', today()->addWeeks(3), today()->addWeeks(2)],
        ['three-months', today()->addMonths(3), today()->addMonths(2)],
        ['seven-weeks', today()->addWeeks(7), today()->addWeeks(6)],
    ];

    foreach ($deadlineCases as [$case, $deliveryDeadline, $expectedFactoryDeadline]) {
        $order = Order::create([
            'order_id' => "FACTORY-DEADLINE-{$case}",
            'guest_name' => 'Factory Deadline Guest',
            'total_order' => 100,
            'deadline' => $deliveryDeadline->toDateString(),
            'status' => 'None',
        ]);
        $item = $order->items()->create([
            'product' => 'Factory Deadline Product',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'None',
            'factory_status' => 'None',
        ]);

        $item->update([
            'status' => 'Produksi',
            'factory_status' => 'Produksi',
        ]);

        expect($order->fresh()->factory_started_at->toDateString())->toBe('2026-01-01')
            ->and($order->fresh()->factory_deadline->toDateString())->toBe($expectedFactoryDeadline->toDateString());
    }

    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders'],
    ]);
    $order = Order::query()->where('order_id', 'FACTORY-DEADLINE-three-weeks')->firstOrFail();

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSee('Target PF:')
        ->assertSee('15 Jan 2026')
        ->assertSee('22 Jan 2026');

    $this->get(route('orders.index', ['search' => 'FACTORY-DEADLINE-three-weeks']))
        ->assertSee('Target PF:')
        ->assertSee('15/01/2026');
});

test('PF dapat menyetujui pengajuan deadline dan tanggal aktif berubah', function () {
    $requester = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $order = Order::create([
        'order_id' => 'ORD-DEADLINE-APPROVE',
        'guest_name' => 'Deadline Guest',
        'total_order' => 100,
        'deadline' => today()->addDays(30)->toDateString(),
        'pending_deadline' => today()->addDays(20)->toDateString(),
        'pending_deadline_reason' => 'Perlu percepatan jadwal kirim.',
        'pending_deadline_requested_by' => $requester->id,
        'pending_deadline_requested_at' => now(),
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Approval Deadline Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('Pengajuan Percepatan Deadline')
        ->assertSee('Setujui deadline')
        ->assertSee('Tolak pengajuan')
        ->assertSee('Target PF:');

    $this->from(route('orders.show', $order))
        ->patch(route('orders.deadline-change.approve', $order))
        ->assertRedirect(route('orders.show', $order))
        ->assertSessionHas('success');

    expect($order->fresh()->deadline->toDateString())->toBe(today()->addDays(20)->toDateString())
        ->and($order->fresh()->pending_deadline)->toBeNull()
        ->and($order->fresh()->factory_deadline->toDateString())->toBe(today()->addDays(13)->toDateString())
        ->and($order->events()->where('type', 'deadline_change_approved')->exists())->toBeTrue();
});

test('PF dapat menolak pengajuan deadline tanpa mengubah tanggal aktif', function () {
    $requester = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $currentDeadline = today()->addDays(30)->toDateString();
    $order = Order::create([
        'order_id' => 'ORD-DEADLINE-REJECT',
        'guest_name' => 'Deadline Guest',
        'total_order' => 100,
        'deadline' => $currentDeadline,
        'pending_deadline' => today()->addDays(20)->toDateString(),
        'pending_deadline_reason' => 'Perlu percepatan jadwal kirim.',
        'pending_deadline_requested_by' => $requester->id,
        'pending_deadline_requested_at' => now(),
        'status' => 'Proses Produksi',
    ]);

    $this->actingAs($factoryAdmin)
        ->from(route('orders.show', $order))
        ->patch(route('orders.deadline-change.reject', $order), [
            'decision_note' => 'Belum dapat dipenuhi.',
        ])
        ->assertRedirect(route('orders.show', $order))
        ->assertSessionHas('success');

    expect($order->fresh()->deadline->toDateString())->toBe($currentDeadline)
        ->and($order->fresh()->pending_deadline)->toBeNull()
        ->and($order->events()->where('type', 'deadline_change_rejected')->value('description'))
        ->toContain('Belum dapat dipenuhi.');
});

test('pembayaran awal dan cicilan order tersimpan sesuai tanggal dan batas tagihan', function () {
    $guest = Guest::create(['name' => 'Payment Guest']);
    $payload = [
        'order_id' => 'ORD-PAYMENT',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'status' => 'None',
        'paid_amount' => '250,000',
        'payment_date' => '2026-03-15',
        'items' => [[
            'product' => 'Payment Product',
            'dimensions' => '10x10x10',
            'qty' => 2,
            'price' => '250,000',
            'source' => 'Order',
            'status' => 'None',
        ]],
    ];

    $this->post(route('orders.store'), $payload)
        ->assertRedirect(route('orders.index'));

    $order = Order::where('order_id', 'ORD-PAYMENT')->firstOrFail();
    expect($order->payments()
        ->where('amount', 250000)
        ->whereDate('paid_at', '2026-03-15')
        ->exists())->toBeTrue();
    $order->items()->firstOrFail()->update([
        'status' => 'Ready',
        'factory_status' => 'Ready',
    ]);
    expect((float) $order->paid_amount)->toBe(250000.0)
        ->and($order->paid_percentage)->toBe(50.0)
        ->and($order->amount_remaining)->toBe(250000.0)
        ->and($order->is_debt)->toBeFalse();

    $this->get(route('orders.index', ['search' => 'ORD-PAYMENT']))
        ->assertSee('50,0%')
        ->assertSee('Sisa Rp 250,000');

    $this->post(route('orders.payments.store', $order), [
        'amount' => '250,001',
        'paid_at' => '2026-03-16',
    ])->assertSessionHasErrors('amount');

    expect((float) $order->fresh()->paid_amount)->toBe(250000.0);
    $this->assertDatabaseCount('order_payments', 1);

    $this->post(route('orders.payments.store', $order), [
        'amount' => '250,000',
        'paid_at' => '2026-03-16',
    ])->assertRedirect(route('orders.show', $order));

    $this->put(route('orders.update', $order), [
        'order_id' => 'ORD-PAYMENT',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'status' => 'Selesai',
        'items' => [[
            'id' => $order->items()->value('id'),
            'product' => 'Payment Product',
            'qty' => 2,
            'price' => '250,000',
            'source' => 'Order',
            'status' => 'Ready',
        ]],
    ])->assertRedirect(route('orders.index'));

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'paid_amount' => 500000,
        'status' => 'Selesai',
    ]);
    $this->assertDatabaseCount('order_payments', 2);
    expect($order->fresh()->is_paid)->toBeTrue()
        ->and($order->fresh()->is_debt)->toBeFalse();
});

test('order saves the guest source for autofill on future orders and accepts x-separated dimensions', function () {
    $guest = Guest::create(['name' => 'Guest Source Autofill']);

    $this->post(route('orders.store'), [
        'order_id' => 'SPK-GUEST-SOURCE',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Instagram',
        'order_type' => 'Bali',
        'paid_amount' => 0,
        'items' => [[
            'product' => 'Dimension Product',
            'dimensions' => '10x10x10',
            'qty' => 1,
            'price' => 1000,
            'source' => 'Order',
        ]],
    ])
        ->assertRedirect(route('orders.index'));

    $this->assertDatabaseHas('guests', [
        'id' => $guest->id,
        'guest_source' => 'Instagram',
    ]);
    $this->assertDatabaseHas('order_items', [
        'order_id' => Order::query()->where('order_id', 'SPK-GUEST-SOURCE')->value('id'),
        'dimensions' => '10x10x10',
    ]);

    $this->get(route('orders.create'))
        ->assertSee('<label>SPK</label>', false)
        ->assertSee('"guest_source":"Instagram"', false);
});

test('order dapat diubah ke nama tamu baru tanpa guest id', function () {
    $guest = Guest::create(['name' => 'Current Guest']);
    $order = Order::create([
        'order_id' => 'ORD-RENAME-GUEST',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 100000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Guest Rename Product',
        'qty' => 1,
        'price' => 100000,
        'source' => 'Order',
        'status' => 'Ready',
    ]);

    $response = $this->put(route('orders.update', $order), [
        'order_id' => $order->order_id,
        'guest_id' => '',
        'guest_name' => 'Replacement Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'paid_amount' => 0,
        'status' => 'None',
        'items' => [[
            'id' => $item->id,
            'product' => $item->product,
            'qty' => 1,
            'price' => 100000,
            'source' => 'Order',
            'status' => 'Ready',
        ]],
    ]);

    $response->assertRedirect(route('orders.index'));
    $replacementGuest = Guest::where('name', 'Replacement Guest')->firstOrFail();
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'guest_id' => $replacementGuest->id,
        'guest_name' => 'Replacement Guest',
    ]);
});

test('order baru dapat menyimpan nama tamu tanpa guest id', function () {
    $response = $this->post(route('orders.store'), [
        'order_id' => 'ORD-NEW-GUEST',
        'guest_id' => '',
        'guest_name' => 'First Order Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'status' => 'None',
        'items' => [[
            'product' => 'First Guest Product',
            'dimensions' => '10x10x10',
            'qty' => 1,
            'price' => 100000,
            'source' => 'Order',
            'status' => 'None',
        ]],
    ]);

    $response->assertRedirect(route('orders.index'));
    $guest = Guest::where('name', 'First Order Guest')->firstOrFail();
    $this->assertDatabaseHas('orders', [
        'order_id' => 'ORD-NEW-GUEST',
        'guest_id' => $guest->id,
        'guest_name' => 'First Order Guest',
    ]);
});

test('tamu baru dapat ditambahkan dan dipakai sebagai pilihan order berikutnya', function () {
    $response = $this->postJson(route('guests.store'), [
        'name' => 'Reusable Guest',
    ]);

    $response->assertOk()
        ->assertJsonPath('guest.name', 'Reusable Guest')
        ->assertJsonMissingPath('guest.phone');

    $this->assertDatabaseHas('guests', [
        'name' => 'Reusable Guest',
    ]);

    $createResponse = $this->get(route('orders.create'));
    $createResponse->assertSee('<option value="Reusable Guest">', false);
});

test('form order menyediakan tamu yang sudah tersimpan di order sebelumnya', function () {
    $guest = Guest::create(['name' => 'Existing Order Guest']);
    $guest->phones()->create(['phone' => '0899000000']);

    Order::create([
        'order_id' => 'ORD-OLD-GUEST',
        'guest_id' => $guest->id,
        'guest_name' => 'Existing Order Guest',
        'phone' => '0899000000',
        'total_order' => 0,
        'status' => 'None',
    ]);

    $response = $this->get(route('orders.create'));

    $response->assertSee('<option value="Existing Order Guest">', false);
});

test('nomor baru dicatat di guest ID yang sama tanpa membuat guest baru', function () {
    $guest = Guest::create(['name' => 'Guest With Changing Phone']);
    $guest->phones()->create(['phone' => '0811111111']);

    foreach ([['ORD-PHONE-1', '0811111111'], ['ORD-PHONE-2', '0822222222']] as [$orderId, $phone]) {
        $this->post(route('orders.store'), [
            'order_id' => $orderId,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'phone' => $phone,
            'status' => 'None',
            'items' => [[
                'product' => 'Product '.$orderId,
                'dimensions' => '10x10x10',
                'qty' => 1,
                'price' => 100,
                'source' => 'Order',
                'status' => 'None',
            ]],
        ])->assertRedirect(route('orders.index'));
    }

    expect(Guest::where('name', $guest->name)->count())->toBe(1)
        ->and($guest->phones()->orderBy('phone')->pluck('phone')->all())
        ->toBe(['0811111111', '0822222222']);

    $this->assertDatabaseHas('orders', [
        'order_id' => 'ORD-PHONE-1',
        'guest_id' => $guest->id,
        'phone' => '0811111111',
    ]);
    $this->assertDatabaseHas('orders', [
        'order_id' => 'ORD-PHONE-2',
        'guest_id' => $guest->id,
        'phone' => '0822222222',
    ]);

    $guestListResponse = $this->get(route('guests.index'));
    $guestListResponse->assertSee('0811111111')
        ->assertSee('0822222222')
        ->assertSee('ORD-PHONE-1')
        ->assertSee('ORD-PHONE-2');

    expect(substr_count($guestListResponse->getContent(), $guest->name))->toBe(1);
});

test('daftar tamu mengurutkan nama dan menyediakan filter jumlah order', function () {
    $guestIds = [];

    foreach ([
        ['ORD-GUEST-Z-1', 'Zulu Guest', '0800000001', 100],
        ['ORD-GUEST-Z-2', 'Zulu Guest', '0800000001', 200],
        ['ORD-GUEST-A-1', 'Alpha Guest', '0800000002', 300],
        ['ORD-GUEST-M-1', 'Mike Guest', '0800000003', 400],
        ['ORD-GUEST-M-2', 'Mike Guest', '0800000003', 500],
        ['ORD-GUEST-M-3', 'Mike Guest', '0800000003', 600],
    ] as [$orderId, $guestName, $phone, $total]) {
        $guestKey = mb_strtolower($guestName);

        if (! isset($guestIds[$guestKey])) {
            $guest = Guest::create(['name' => $guestName]);
            $guestIds[$guestKey] = $guest->id;
        }

        $guest->phones()->firstOrCreate(['phone' => $phone]);

        Order::create([
            'order_id' => $orderId,
            'guest_id' => $guestIds[$guestKey],
            'guest_name' => $guestName,
            'phone' => $phone,
            'total_order' => $total,
            'status' => 'None',
        ]);
    }

    $alphabeticalResponse = $this->get(route('guests.index'));
    $alphabeticalResponse->assertSeeInOrder(['Alpha Guest', 'Mike Guest', 'Zulu Guest'])
        ->assertSee('value="alphabetical" selected', false)
        ->assertSee('0800000003')
        ->assertSee('ORD-GUEST-M-1')
        ->assertSee('ORD-GUEST-M-2')
        ->assertSee('ORD-GUEST-M-3');

    $mostResponse = $this->get(route('guests.index', ['sort' => 'most']));
    $mostResponse->assertSeeInOrder(['Mike Guest', 'Zulu Guest', 'Alpha Guest'])
        ->assertSee('value="most" selected', false);

    $fewestResponse = $this->get(route('guests.index', ['sort' => 'fewest']));
    $fewestResponse->assertSeeInOrder(['Alpha Guest', 'Zulu Guest', 'Mike Guest'])
        ->assertSee('value="fewest" selected', false);
});

test('daftar tamu mengurutkan alfabet tanpa membedakan huruf kapital', function () {
    $devano = Guest::create(['name' => 'Devano']);
    $devano->phones()->create(['phone' => '0800000005']);
    $cef = Guest::create(['name' => 'cef']);
    $cef->phones()->create(['phone' => '0800000004']);

    Order::create([
        'order_id' => 'ORD-GUEST-DEVANO',
        'guest_id' => $devano->id,
        'guest_name' => 'Devano',
        'phone' => '0800000005',
        'total_order' => 100,
        'status' => 'None',
    ]);

    Order::create([
        'order_id' => 'ORD-GUEST-CEF',
        'guest_id' => $cef->id,
        'guest_name' => 'cef',
        'phone' => '0800000004',
        'total_order' => 100,
        'status' => 'None',
    ]);

    $response = $this->get(route('guests.index'));

    $response->assertSeeInOrder(['cef', 'Devano']);
});

test('laporan kategori mengurutkan jumlah unit terjual dan tidak menghitung order yang dibatalkan', function () {
    $guest = Guest::create(['name' => 'Category Report Guest']);
    $popularCategory = Category::create(['name' => 'Popular Category']);
    $lessPopularCategory = Category::create(['name' => 'Less Popular Category']);
    $cancelledCategory = Category::create(['name' => 'Cancelled Category']);

    $orders = collect([
        ['ORD-CATEGORY-1', 'None', 100],
        ['ORD-CATEGORY-2', 'Selesai', 200],
        ['ORD-CATEGORY-CANCELLED', 'Cancel', 300],
    ])->map(fn ($attributes) => Order::create([
        'order_id' => $attributes[0],
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => $attributes[2],
        'status' => $attributes[1],
    ]));

    $orders[0]->items()->create([
        'product' => 'Popular Product One',
        'category_id' => $popularCategory->id,
        'qty' => 4,
        'price' => 25,
    ]);
    $orders[1]->items()->create([
        'product' => 'Popular Product Two',
        'category_id' => $popularCategory->id,
        'qty' => 3,
        'price' => 25,
    ]);
    $orders[0]->items()->create([
        'product' => 'Less Popular Product',
        'category_id' => $lessPopularCategory->id,
        'qty' => 5,
        'price' => 20,
    ]);
    $orders[2]->items()->create([
        'product' => 'Cancelled Product',
        'category_id' => $cancelledCategory->id,
        'qty' => 100,
        'price' => 3,
    ]);

    $response = $this->get(route('reports.popular-categories'));

    $response->assertSeeInOrder([
        'Popular Category',
        '7 unit',
        'Less Popular Category',
        '5 unit',
    ])->assertDontSee('Cancelled Category');
});

test('profil tamu menampilkan total belanja dan riwayat pembelian milik tamu saja', function () {
    $guest = Guest::create([
        'name' => 'History <script>alert("guest")</script>',
        'address' => 'Jl. Melati No. 10',
    ]);
    $guest->phones()->create(['phone' => '08123456789']);
    $category = Category::create(['name' => 'Guest History Category']);

    $purchase = Order::create([
        'order_id' => 'ORD-GUEST-HISTORY',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 350000,
        'status' => 'Selesai',
    ]);
    $purchase->items()->create([
        'product' => '<script>alert("product")</script>',
        'category_id' => $category->id,
        'qty' => 2,
        'price' => 175000,
    ]);

    Order::create([
        'order_id' => 'ORD-GUEST-CANCELLED',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 900000,
        'status' => 'Cancel',
    ]);

    Order::create([
        'order_id' => 'ORD-OTHER-GUEST-HISTORY',
        'guest_name' => 'Other Guest',
        'total_order' => 100000,
        'status' => 'Selesai',
    ]);

    $response = $this->get(route('guests.show', $guest));

    $response->assertSee('Jl. Melati No. 10')
        ->assertSee('08123456789')
        ->assertSee('Rp 350.000')
        ->assertSee('ORD-GUEST-HISTORY')
        ->assertSee('Guest History Category')
        ->assertSee('× 2')
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee('ORD-GUEST-CANCELLED')
        ->assertDontSee('ORD-OTHER-GUEST-HISTORY')
        ->assertDontSee('<script>alert("guest")</script>', false)
        ->assertDontSee('<script>alert("product")</script>', false);
});

test('profil tamu yang tidak ditemukan mengembalikan 404', function () {
    $response = $this->get(route('guests.show', 999999));

    $response->assertNotFound();
});

test('index order mencari berdasarkan informasi order dan produk', function () {
    $matchingOrder = Order::create([
        'order_id' => 'ORD-SEARCH-MATCH',
        'guest_name' => 'Search Guest',
        'phone' => '0812345678',
        'total_order' => 100,
        'status' => 'None',
    ]);

    $matchingOrder->items()->create([
        'product' => 'Oak Dining Chair',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'None',
    ]);

    Order::create([
        'order_id' => 'ORD-SEARCH-OTHER',
        'guest_name' => 'Other Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);

    $response = $this->get(route('orders.index', ['search' => 'Oak Dining']));

    $response->assertSee('ORD-SEARCH-MATCH')
        ->assertSee('Oak Dining Chair')
        ->assertDontSee('ORD-SEARCH-OTHER');
});

test('index order menampilkan order tertua lebih dahulu dan mempertahankan pencarian pada pagination', function () {
    $newerOrder = Order::create([
        'order_id' => 'ORD-NEW',
        'guest_name' => 'Guest New',
        'total_order' => 0,
        'status' => 'None',
    ]);

    $olderOrder = Order::create([
        'order_id' => 'ORD-OLD',
        'guest_name' => 'Guest Old',
        'total_order' => 0,
        'status' => 'None',
    ]);

    $olderOrder->forceFill(['created_at' => now()->subDay()])->save();
    $newerOrder->forceFill(['created_at' => now()])->save();

    foreach (range(1, 10) as $index) {
        Order::create([
            'order_id' => "ORD-PAGE-{$index}",
            'guest_name' => "Guest {$index}",
            'total_order' => 0,
            'status' => 'None',
        ]);
    }

    $response = $this->get(route('orders.index', ['search' => 'ORD-']));

    $response->assertSeeInOrder(['ORD-OLD', 'ORD-NEW'])
        ->assertSee('search=ORD-&amp;page=2', false)
        ->assertSee('name="search"', false)
        ->assertSee('value="ORD-"', false);
});

test('index mengurutkan deadline terdekat lebih dahulu lalu order tanpa deadline', function () {
    $furthestOrder = Order::create([
        'order_id' => 'ORD-FURTHEST',
        'guest_name' => 'Furthest Guest',
        'total_order' => 0,
        'deadline' => today()->addDays(20),
        'status' => 'Proses Produksi',
    ]);

    $closestOrder = Order::create([
        'order_id' => 'ORD-CLOSEST',
        'guest_name' => 'Closest Guest',
        'total_order' => 0,
        'deadline' => today()->addDays(2),
        'status' => 'Proses Produksi',
    ]);

    Order::create([
        'order_id' => 'ORD-NO-DEADLINE',
        'guest_name' => 'No Deadline Guest',
        'total_order' => 0,
        'status' => 'Proses Produksi',
    ]);

    $furthestOrder->forceFill(['created_at' => now()->subDay()])->save();
    $closestOrder->forceFill(['created_at' => now()])->save();

    $response = $this->get(route('orders.index', ['search' => 'ORD-']));

    $response->assertSeeInOrder([
        'ORD-CLOSEST',
        'ORD-FURTHEST',
        'ORD-NO-DEADLINE',
    ]);
});

test('index menyediakan empat pilihan pengurutan order', function () {
    $oldestOrder = Order::create([
        'order_id' => 'ORD-SORT-OLDEST',
        'guest_name' => 'Oldest Guest',
        'total_order' => 1,
        'deadline' => today()->addDays(15),
        'status' => 'Proses Produksi',
    ]);

    $mostProductsOrder = Order::create([
        'order_id' => 'ORD-SORT-MOST',
        'guest_name' => 'Most Products Guest',
        'total_order' => 10,
        'deadline' => today()->addDays(5),
        'status' => 'Proses Produksi',
    ]);

    $furthestDeadlineOrder = Order::create([
        'order_id' => 'ORD-SORT-FURTHEST',
        'guest_name' => 'Furthest Deadline Guest',
        'total_order' => 2,
        'deadline' => today()->addDays(30),
        'status' => 'Proses Produksi',
    ]);

    $closestDeadlineOrder = Order::create([
        'order_id' => 'ORD-SORT-CLOSEST',
        'guest_name' => 'Closest Deadline Guest',
        'total_order' => 3,
        'deadline' => today()->addDay(),
        'status' => 'Proses Produksi',
    ]);

    $completedOrder = Order::create([
        'order_id' => 'ORD-SORT-COMPLETED',
        'guest_name' => 'Completed Guest',
        'total_order' => 100,
        'deadline' => today()->addDays(60),
        'status' => 'Selesai',
    ]);

    foreach ([
        [$oldestOrder, 1, 'Oldest Product'],
        [$mostProductsOrder, 10, 'Most Products'],
        [$furthestDeadlineOrder, 2, 'Furthest Product'],
        [$closestDeadlineOrder, 3, 'Closest Product'],
        [$completedOrder, 100, 'Completed Product'],
    ] as [$order, $qty, $product]) {
        $order->items()->create([
            'product' => $product,
            'qty' => $qty,
            'price' => 1,
            'source' => 'Order',
            'status' => $order->id === $completedOrder->id ? 'Ready' : 'Produksi',
        ]);
    }

    $oldestOrder->forceFill(['created_at' => now()->subDays(5)])->save();
    $mostProductsOrder->forceFill(['created_at' => now()->subDays(4)])->save();
    $furthestDeadlineOrder->forceFill(['created_at' => now()->subDays(3)])->save();
    $closestDeadlineOrder->forceFill(['created_at' => now()->subDays(2)])->save();

    $oldestResponse = $this->get(route('orders.index', ['search' => 'ORD-SORT-', 'sort' => 'oldest']));
    $oldestResponse->assertSeeInOrder([
        'ORD-SORT-OLDEST',
        'ORD-SORT-MOST',
        'ORD-SORT-FURTHEST',
        'ORD-SORT-CLOSEST',
        'ORD-SORT-COMPLETED',
    ])->assertSee('value="oldest" selected', false);

    $mostResponse = $this->get(route('orders.index', ['search' => 'ORD-SORT-', 'sort' => 'most']));
    $mostResponse->assertSeeInOrder([
        'ORD-SORT-MOST',
        'ORD-SORT-CLOSEST',
        'ORD-SORT-FURTHEST',
        'ORD-SORT-OLDEST',
        'ORD-SORT-COMPLETED',
    ])->assertSee('value="most" selected', false);

    $moreTimeResponse = $this->get(route('orders.index', ['search' => 'ORD-SORT-', 'sort' => 'remaining_more']));
    $moreTimeResponse->assertSeeInOrder([
        'ORD-SORT-FURTHEST',
        'ORD-SORT-OLDEST',
        'ORD-SORT-MOST',
        'ORD-SORT-CLOSEST',
        'ORD-SORT-COMPLETED',
    ])->assertSee('value="remaining_more" selected', false);

    $lessTimeResponse = $this->get(route('orders.index', ['search' => 'ORD-SORT-', 'sort' => 'remaining_less']));
    $lessTimeResponse->assertSeeInOrder([
        'ORD-SORT-CLOSEST',
        'ORD-SORT-MOST',
        'ORD-SORT-OLDEST',
        'ORD-SORT-FURTHEST',
        'ORD-SORT-COMPLETED',
    ])->assertSee('value="remaining_less" selected', false);

    $defaultResponse = $this->get(route('orders.index', ['search' => 'ORD-SORT-']));
    $defaultResponse->assertSeeInOrder([
        'ORD-SORT-CLOSEST',
        'ORD-SORT-MOST',
        'ORD-SORT-OLDEST',
        'ORD-SORT-FURTHEST',
        'ORD-SORT-COMPLETED',
    ])->assertSee('value="remaining_less" selected', false);
});

test('jenis order memfilter daftar dan ekspor CSV menyertakan alamat tamu', function () {
    $exportGuest = Guest::create([
        'name' => 'Export Guest',
        'address' => 'Jalan Ekspor 10',
    ]);

    Order::create([
        'order_id' => 'ORD-EXPORT-MATCH',
        'guest_id' => $exportGuest->id,
        'guest_name' => $exportGuest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Ekspor',
        'total_order' => 120000,
        'status' => 'Proses Produksi',
    ]);

    Order::create([
        'order_id' => 'ORD-EXPORT-OTHER',
        'guest_name' => 'Local Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 50000,
        'status' => 'Proses Produksi',
    ]);

    $listResponse = $this->get(route('orders.index', ['order_type' => 'Ekspor']));
    $exportResponse = $this->get(route('orders.export', ['order_type' => 'Ekspor']));

    $listResponse->assertSee('ORD-EXPORT-MATCH')
        ->assertDontSee('ORD-EXPORT-OTHER');
    $exportResponse->assertOk();
    $exportResponse->assertDownload('daftar-order.csv');

    expect($exportResponse->streamedContent())
        ->toContain('ORD-EXPORT-MATCH')
        ->toContain('Jalan Ekspor 10')
        ->not->toContain('ORD-EXPORT-OTHER');
});

test('index menempatkan order selesai di bawah order yang belum selesai', function () {
    $completedOrder = Order::create([
        'order_id' => 'ORD-COMPLETED',
        'guest_name' => 'Completed Guest',
        'total_order' => 0,
        'deadline' => today()->addDay(),
        'status' => 'Selesai',
    ]);

    $unfinishedOrder = Order::create([
        'order_id' => 'ORD-UNFINISHED',
        'guest_name' => 'Unfinished Guest',
        'total_order' => 0,
        'deadline' => today()->addDays(14),
        'status' => 'Proses Produksi',
    ]);

    $completedOrder->forceFill(['created_at' => now()->subDay()])->save();
    $unfinishedOrder->forceFill(['created_at' => now()])->save();

    $response = $this->get(route('orders.index', ['search' => 'ORD-']));

    $response->assertSeeInOrder(['ORD-UNFINISHED', 'ORD-COMPLETED']);
});

test('index menampilkan sisa waktu deadline hanya untuk order yang belum selesai', function () {
    $unfinishedOrder = Order::create([
        'order_id' => 'ORD-DUE-SOON',
        'guest_name' => 'Due Soon Guest',
        'total_order' => 0,
        'deadline' => today()->addDays(14),
        'status' => 'Proses Produksi',
    ]);

    Order::create([
        'order_id' => 'ORD-DUE-COMPLETED',
        'guest_name' => 'Completed Guest',
        'total_order' => 0,
        'deadline' => today()->addDays(14),
        'status' => 'Selesai',
    ]);

    $response = $this->get(route('orders.index', ['search' => 'ORD-DUE-']));

    $response->assertSeeText('14 hari / 2 minggu tersisa')
        ->assertSeeInOrder(['ORD-DUE-SOON', 'ORD-DUE-COMPLETED']);
});

test('index menandai deadline hari ini dan deadline yang sudah lewat', function () {
    Order::create([
        'order_id' => 'ORD-DUE-TODAY',
        'guest_name' => 'Today Guest',
        'total_order' => 0,
        'deadline' => today(),
        'status' => 'Proses Produksi',
    ]);

    Order::create([
        'order_id' => 'ORD-DUE-PAST',
        'guest_name' => 'Past Guest',
        'total_order' => 0,
        'deadline' => today()->subDays(3),
        'status' => 'Proses Produksi',
    ]);

    $response = $this->get(route('orders.index', ['search' => 'ORD-DUE-']));

    $response->assertSeeText('Deadline hari ini')
        ->assertSeeText('Terlambat 3 hari');
});

test('order menyimpan jenis order dan memperbarui alamat tamu opsional', function () {
    $guest = Guest::create(['name' => 'Guest With Address']);

    $response = $this->post(route('orders.store'), [
        'order_id' => 'ORD-ADDRESS',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'guest_address' => 'Jalan Melati 25',
        'order_type' => 'Luar Bali',
        'status' => 'None',
        'items' => [[
            'product' => 'Address Test Product',
            'dimensions' => '10x10x10',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'None',
        ]],
    ]);

    $response->assertRedirect(route('orders.index'));
    $this->assertDatabaseHas('orders', [
        'order_id' => 'ORD-ADDRESS',
        'order_type' => 'Luar Bali',
    ]);
    $this->assertDatabaseHas('guests', [
        'id' => $guest->id,
        'address' => 'Jalan Melati 25',
    ]);
});

test('order yang terlambat ditampilkan urgent kecuali order selesai atau dibatalkan', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));

    $overdueOrder = Order::create([
        'order_id' => 'ORD-OVERDUE-STATUS',
        'guest_name' => 'Overdue Guest',
        'total_order' => 0,
        'deadline' => today()->subDay(),
        'status' => 'Proses Produksi',
    ]);

    $completedOrder = Order::create([
        'order_id' => 'ORD-OVERDUE-COMPLETED',
        'guest_name' => 'Completed Guest',
        'total_order' => 0,
        'deadline' => today()->subDay(),
        'status' => 'Selesai',
    ]);

    $cancelledOrder = Order::create([
        'order_id' => 'ORD-OVERDUE-CANCELLED',
        'guest_name' => 'Cancelled Guest',
        'total_order' => 0,
        'deadline' => today()->subDay(),
        'status' => 'Cancel',
    ]);

    $indexResponse = $this->get(route('orders.index', ['search' => 'ORD-OVERDUE-']));
    $overdueResponse = $this->get(route('orders.show', $overdueOrder));
    $completedResponse = $this->get(route('orders.show', $completedOrder));
    $cancelledResponse = $this->get(route('orders.show', $cancelledOrder));

    $indexResponse->assertSee('ORD-OVERDUE-STATUS')
        ->assertSeeText('URGENT')
        ->assertSeeText('Terlambat 1 hari');
    $overdueResponse->assertSeeText('URGENT');
    $completedResponse->assertDontSee('URGENT')
        ->assertSeeText('Selesai');
    $cancelledResponse->assertDontSee('URGENT')
        ->assertSeeText('Cancel');
});

test('jenis order wajib dipilih saat menyimpan order', function () {
    $guest = Guest::create(['name' => 'Type Required Guest']);

    $response = $this->post(route('orders.store'), [
        'order_id' => 'ORD-TYPE-REQUIRED',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'status' => 'None',
        'items' => [[
            'product' => 'Type Required Product',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'None',
        ]],
    ]);

    $response->assertSessionHasErrors('order_type');
    $this->assertDatabaseMissing('orders', [
        'order_id' => 'ORD-TYPE-REQUIRED',
    ]);
});

test('form order baru tidak menyediakan pilihan status order', function () {
    $response = $this->get(route('orders.create'));

    $response->assertSee('Status Order')
        ->assertDontSee('<option value="Service"', false)
        ->assertSee('Sumber Tamu')
        ->assertSee('Lokasi Tujuan')
        ->assertSee('Ekspor')
        ->assertSee('Bali')
        ->assertSee('Luar Bali')
        ->assertSee('Alamat Tamu (opsional)')
        ->assertSee('Lokal')
        ->assertSee('Gmaps')
        ->assertSee('Instagram')
        ->assertSee('Website')
        ->assertSee('data-deadline-control', false)
        ->assertSee('data-deadline-date', false)
        ->assertSee('data-deadline-days', false)
        ->assertSee('New')
        ->assertDontSee('name="status"', false)
        ->assertDontSee('name="items[${productIndex}][status]"', false)
        ->assertDontSee('name="items[${productIndex}][status_images][]"', false);

    $guest = Guest::create(['name' => 'Read Only Order Guest']);
    $order = Order::create([
        'order_id' => 'READ-ONLY-ORDER-STATUS',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'status' => 'None',
    ]);

    $this->get(route('orders.edit', $order))
        ->assertSee('Status Order')
        ->assertSee('New')
        ->assertDontSee('<select name="status">', false);
});

test('produk Stock baru masuk antrean distribusi PB dan tidak muncul di Factory', function () {
    Storage::fake('public');
    $guest = Guest::create(['name' => 'Guest']);

    $payload = [
        'order_id' => 'ORD-003',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest',
        'guest_source' => 'Online',
        'order_type' => 'Ekspor',
        'status' => 'None',
        'items' => [
            [
                'product' => 'Product One',
                'dimensions' => '10x10x10',
                'qty' => 1,
                'price' => 100,
                'source' => 'Stock',
                'status' => 'Produksi',
                'status_image' => UploadedFile::fake()->create('production.jpg', 100, 'image/jpeg'),
            ],
        ],
    ];

    $this->post(route('orders.store'), $payload)
        ->assertSessionHasErrors(['items.0.status', 'items.0.status_image']);

    unset($payload['items'][0]['status'], $payload['items'][0]['status_image']);
    $response = $this->post(route('orders.store'), $payload);

    $response->assertRedirect(route('orders.index'));
    $order = Order::where('order_id', 'ORD-003')->firstOrFail();
    $item = $order->items()->firstOrFail();

    expect($order->status)->toBe('Distribusi')
        ->and($order->guest_source)->toBe('Online')
        ->and($item->status)->toBe('Distribusi')
        ->and($item->factory_status)->toBe('None')
        ->and($item->status_image)->toBeNull();

    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders', 'order_products'],
    ]);
    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertDontSeeText('ORD-003')
        ->assertDontSeeText('Product One');
    $this->get(route('orders.index'))
        ->assertDontSeeText('ORD-003')
        ->assertDontSeeText('Product One');
    $this->get(route('orders.show', $order))->assertNotFound();
});

test('endpoint produksi menolak perubahan status order tanpa perubahan produk', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Order Status Endpoint Guest']);
    $order = Order::create([
        'order_id' => 'AUTOMATIC-STATUS-ENDPOINT',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'status' => 'None',
    ]);

    $this->actingAs($admin)
        ->patch(route('orders.production.update', $order), [
            'status' => 'Selesai',
        ])
        ->assertSessionHasErrors('items');

    expect($order->fresh()->status)->toBe('None');
});

test('order changes to production when every product enters production', function () {
    Storage::fake('public');
    $guest = Guest::create(['name' => 'Production Status Guest']);
    $deliveryDeadline = today()->addWeeks(3)->toDateString();
    $order = Order::create([
        'order_id' => 'ALL-PRODUCTS-PRODUCTION',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 20000,
        'deadline' => $deliveryDeadline,
        'status' => 'None',
    ]);
    $firstItem = $order->items()->create([
        'product' => 'Production Chair',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);
    $secondItem = $order->items()->create([
        'product' => 'Production Table',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('orders.edit', $order))
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $firstItem->id,
                'status' => 'Produksi',
                'status_images' => [UploadedFile::fake()->create('chair-mockup.jpg', 100, 'image/jpeg')],
            ]],
        ])
        ->assertRedirect(route('orders.edit', $order));

    expect($order->fresh()->status)->toBe('None')
        ->and($order->fresh()->factory_started_at)->not->toBeNull()
        ->and($order->fresh()->factory_deadline->toDateString())->toBe(today()->addWeeks(2)->toDateString());

    $this->from(route('orders.edit', $order))
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $secondItem->id,
                'status' => 'Produksi',
                'status_images' => [UploadedFile::fake()->create('table-mockup.jpg', 100, 'image/jpeg')],
            ]],
        ])
        ->assertRedirect(route('orders.edit', $order));

    expect($order->fresh()->status)->toBe('Proses Produksi')
        ->and($firstItem->fresh()->factory_status)->toBe('None')
        ->and($secondItem->fresh()->factory_status)->toBe('None');

    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);

    $this->actingAs($factoryAdmin)
        ->patch(route('order-products.factory-drawing.update', $firstItem), [
            'factory_drawing_image' => UploadedFile::fake()->create('chair-work-drawing.jpg', 100, 'image/jpeg'),
            'factory_sale_price' => '100,000',
        ])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe('Proses Produksi');

    $this->patch(route('order-products.factory-drawing.update', $secondItem), [
        'factory_drawing_image' => UploadedFile::fake()->create('table-work-drawing.jpg', 100, 'image/jpeg'),
        'factory_sale_price' => '100,000',
    ])->assertRedirect();

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'Proses Produksi',
    ]);
    $this->assertDatabaseHas('order_items', [
        'id' => $firstItem->id,
        'status' => 'Produksi',
    ]);
    $this->assertDatabaseHas('order_items', [
        'id' => $secondItem->id,
        'status' => 'Produksi',
    ]);
});

test('order status follows PB production while PF drawings are pending', function () {
    $order = Order::create([
        'order_id' => 'MODEL-PRODUCTION-STATUS',
        'guest_name' => 'Model Status Guest',
        'total_order' => 200,
        'status' => 'None',
    ]);
    $firstItem = $order->items()->create([
        'product' => 'Model Status Chair',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'None',
    ]);
    $secondItem = $order->items()->create([
        'product' => 'Model Status Table',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'None',
    ]);

    $firstItem->update(['status' => 'Produksi']);
    expect($order->fresh()->status)->toBe('None');

    $secondItem->update(['status' => 'Produksi']);

    expect($order->fresh()->status)->toBe('Proses Produksi');

    $firstItem->update(['factory_drawing_image' => 'orders/factory-drawings/model-chair.jpg']);
    $order->synchronizeProductionStatusFromItems();
    expect($order->fresh()->status)->toBe('Proses Produksi');

    $secondItem->update(['factory_drawing_image' => 'orders/factory-drawings/model-table.jpg']);
    $order->synchronizeProductionStatusFromItems();

    expect($order->fresh()->status)->toBe('Proses Produksi')
        ->and($firstItem->fresh()->factory_status)->toBe('None')
        ->and($secondItem->fresh()->factory_status)->toBe('None');
});

test('stale new order status is reconciled when the order edit page is opened', function () {
    $order = Order::create([
        'order_id' => 'STALE-PRODUCTION-STATUS',
        'guest_name' => 'Stale Status Guest',
        'total_order' => 200,
        'status' => 'None',
    ]);
    $order->items()->createMany([
        [
            'product' => 'Stale Production Chair',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'Produksi',
            'factory_drawing_image' => 'orders/factory-drawings/stale-chair.jpg',
        ],
        [
            'product' => 'Stale Production Table',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'Produksi',
            'factory_drawing_image' => 'orders/factory-drawings/stale-table.jpg',
        ],
    ]);

    $this->get(route('orders.edit', $order))
        ->assertSeeText('Proses Produksi');

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'Proses Produksi',
    ]);
});

test('order returns to new when an unstarted product is added to an order in production', function () {
    $guest = Guest::create(['name' => 'Additional Product Guest']);
    $order = Order::create([
        'order_id' => 'ADD-ITEM-AFTER-PRODUCTION',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100,
        'status' => 'Proses Produksi',
    ]);
    $existingItem = $order->items()->create([
        'product' => 'Existing Production Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $response = $this->put(route('orders.update', $order), [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'items' => [
            [
                'id' => $existingItem->id,
                'product' => $existingItem->product,
                'qty' => 1,
                'price' => 100,
                'source' => 'Order',
                'status' => 'Produksi',
            ],
            [
                'product' => 'New Guest Addition',
                'dimensions' => '10x10x10',
                'qty' => 1,
                'price' => 100,
                'source' => 'Order',
                'status' => 'None',
            ],
        ],
    ]);

    $response->assertRedirect(route('orders.index'));

    $newItem = $order->items()->where('product', 'New Guest Addition')->firstOrFail();
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'order_status_changed',
        'description' => 'ADD-ITEM-AFTER-PRODUCTION: Proses Produksi -> None (menunggu mockup PB dan gambar kerja PF untuk produk baru)',
    ]);

    expect($order->fresh()->status)->toBe('None')
        ->and($newItem->status)->toBe('None');
});

test('orders with production progress cannot be deleted', function () {
    $order = Order::create([
        'order_id' => 'PRODUCTION-DELETE-GUARD',
        'guest_name' => 'Delete Guard Guest',
        'total_order' => 100,
        'status' => 'None',
    ]);
    $order->items()->create([
        'product' => 'Production Delete Guard Item',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->delete(route('orders.destroy', $order))
        ->assertRedirect(route('orders.index'))
        ->assertSessionHas('error', 'Order tidak dapat dihapus setelah proses produksi dimulai.');

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
    ]);

    $this->get(route('orders.index', ['search' => $order->order_id]))
        ->assertDontSee('aria-label="Hapus order '.$order->order_id.'"', false);
});

test('order accepts a custom guest source and the new destination values', function () {
    $guest = Guest::create(['name' => 'Custom Source Guest']);

    $this->post(route('orders.store'), [
        'order_id' => 'ORD-CUSTOM-SOURCE',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => '__custom',
        'guest_source_custom' => 'Wedding Organizer',
        'order_type' => 'Luar Bali',
        'status' => 'None',
        'items' => [[
            'product' => 'Custom Source Product',
            'dimensions' => '10x10x10',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'None',
        ]],
    ])->assertRedirect(route('orders.index'));

    $this->assertDatabaseHas('orders', [
        'order_id' => 'ORD-CUSTOM-SOURCE',
        'guest_source' => 'Wedding Organizer',
        'order_type' => 'Luar Bali',
    ]);
    $this->assertDatabaseHas('guests', [
        'id' => $guest->id,
        'guest_source' => 'Wedding Organizer',
    ]);
});

test('order list reports ready percentage using total item quantity', function () {
    $order = Order::create([
        'order_id' => 'ORD-READY-UNITS',
        'guest_name' => 'Ready Units Guest',
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Ready Chairs',
        'qty' => 3,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Ready',
    ]);
    $order->items()->create([
        'product' => 'Working Table',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $this->get(route('orders.index', ['search' => 'ORD-READY-UNITS']))
        ->assertSeeText('75,0%')
        ->assertSeeText('3/4 unit');

    $this->actingAs(User::factory()->factoryAdmin()->create())
        ->get(route('orders.index', ['search' => 'ORD-READY-UNITS']))
        ->assertSeeText('75,0%')
        ->assertSeeText('3/4 unit');
});

test('order rejects retired product sources for new items', function (string $source) {
    $response = $this->from(route('orders.create'))->post(route('orders.store'), [
        'order_id' => 'ORD-RETIRED-SOURCE-'.$source,
        'guest_name' => 'Retired Source Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Luar Bali',
        'status' => 'None',
        'items' => [
            [
                'product' => 'Retired Source Product',
                'dimensions' => '10x10x10',
                'qty' => 1,
                'price' => 50000,
                'source' => $source,
                'status' => 'None',
            ],
        ],
    ]);

    $response->assertSessionHasErrors('items.0.source');

    $this->assertDatabaseMissing('order_items', [
        'product' => 'Retired Source Product',
    ]);
})->with(['Mix', 'Service']);

test('order source menus remove Mix and Service while retaining legacy values for existing items', function () {
    $createResponse = $this->get(route('orders.create'));
    $createResponse->assertDontSee('value="Mix"', false)
        ->assertDontSee('value="Service"', false);

    $guest = Guest::create(['name' => 'Legacy Source Guest']);
    $order = Order::create([
        'order_id' => 'ORD-LEGACY-SOURCE',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 100,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Legacy Service',
        'dimensions' => '10x10x10',
        'qty' => 1,
        'price' => 100,
        'source' => 'Service',
        'status' => 'None',
    ]);

    $editResponse = $this->get(route('orders.edit', $order));
    $editResponse->assertDontSee('<option value="Mix"', false)
        ->assertDontSee('<option value="Service"', false)
        ->assertSee('name="items[0][source]" value="Service"', false);

    $this->put(route('orders.update', $order), [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'paid_amount' => 0,
        'items' => [[
            'id' => $item->id,
            'product' => $item->product,
            'dimensions' => $item->dimensions,
            'qty' => $item->qty,
            'price' => $item->price,
            'source' => 'Service',
            'status' => 'None',
        ]],
    ])->assertRedirect(route('orders.index'));

    $this->assertDatabaseHas('order_items', [
        'id' => $item->id,
        'source' => 'Service',
    ]);
});

test('Stock items do not block made-to-order items from starting production', function () {
    $order = Order::create([
        'order_id' => 'STOCK-AND-PRODUCTION',
        'guest_name' => 'Mixed Source Guest',
        'total_order' => 200,
        'status' => 'None',
    ]);
    $order->items()->create([
        'product' => 'Existing Stock',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Distribusi',
        'factory_status' => 'None',
    ]);
    $order->items()->create([
        'product' => 'Made To Order Chair',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/ready-for-pf.jpg',
    ]);

    $order->synchronizeProductionStatusFromItems();

    expect($order->fresh()->status)->toBe('Proses Produksi');
});

test('mixed Stock and made-to-order items complete the order when all are ready', function () {
    $order = Order::create([
        'order_id' => 'MIXED-STOCK-READY-STATUS',
        'guest_name' => 'Mixed Stock Ready Guest',
        'total_order' => 200,
        'status' => 'Distribusi',
    ]);
    $order->items()->createMany([
        [
            'product' => 'Ready Stock Chair',
            'qty' => 1,
            'price' => 100,
            'source' => 'Stock',
            'status' => 'Ready',
        ],
        [
            'product' => 'Ready Made To Order Table',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'Ready',
        ],
    ]);

    $order->synchronizeProductionStatusFromItems();

    expect($order->fresh()->status)->toBe('Selesai');
});

test('mixed ready and production items keep the order in production', function () {
    $order = Order::create([
        'order_id' => 'MIXED-PRODUCTION-STATUS',
        'guest_name' => 'Mixed Production Guest',
        'total_order' => 300,
        'status' => 'None',
    ]);
    $order->items()->createMany([
        [
            'product' => 'Production Chair',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'Produksi',
            'factory_drawing_image' => 'orders/factory-drawings/production-chair.jpg',
            'factory_status' => 'None',
        ],
        [
            'product' => 'Production Table',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'Produksi',
            'factory_drawing_image' => 'orders/factory-drawings/production-table.jpg',
            'factory_status' => 'None',
        ],
        [
            'product' => 'Ready Shelf',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'Ready',
        ],
    ]);

    $order->synchronizeProductionStatusFromItems();

    expect($order->fresh()->status)->toBe('Proses Produksi')
        ->and($order->items()->where('status', 'Produksi')->pluck('factory_status')->unique()->all())->toBe(['None']);
});

test('active service takes precedence over ready items and completed order status', function () {
    $order = Order::create([
        'order_id' => 'SERVICE-ORDER-STATUS',
        'guest_name' => 'Service Status Guest',
        'total_order' => 200,
        'status' => 'Selesai',
    ]);
    $serviceItem = $order->items()->create([
        'product' => 'Serviced Chair',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Ready',
    ]);
    $order->items()->create([
        'product' => 'Ready Table',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Ready',
    ]);
    $serviceItem->services()->create([
        'quantity' => 1,
        'status' => 'Service',
    ]);

    $order->synchronizeProductionStatusFromItems();

    expect($order->fresh()->status)->toBe('Service');
});

test('active service takes precedence over distributed made-to-order items', function () {
    $order = Order::create([
        'order_id' => 'SERVICE-AND-DISTRIBUTION-STATUS',
        'guest_name' => 'Service and Distribution Guest',
        'total_order' => 200,
        'status' => 'Distribusi',
    ]);
    $serviceItem = $order->items()->create([
        'product' => 'Serviced Stock Chair',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Ready',
    ]);
    $order->items()->create([
        'product' => 'Distributed Table',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Distribusi',
    ]);
    $serviceItem->services()->create([
        'quantity' => 1,
        'status' => 'Service',
    ]);

    $order->synchronizeProductionStatusFromItems();

    expect($order->fresh()->status)->toBe('Service');
});

test('service orders are available in the order status filter', function () {
    $order = Order::create([
        'order_id' => 'SERVICE-STATUS-FILTER',
        'guest_name' => 'Service Filter Guest',
        'total_order' => 100,
        'status' => 'Service',
    ]);

    $this->get(route('orders.index', ['status' => 'Service']))
        ->assertOk()
        ->assertSee($order->order_id)
        ->assertSee('order-status-tab is-service is-active', false)
        ->assertSee('order-status-pill is-service', false);
});

test('packing and distribution orders are available in the order status filter', function () {
    foreach (['Packing', 'Distribusi'] as $status) {
        $order = Order::create([
            'order_id' => "STATUS-FILTER-{$status}",
            'guest_name' => "{$status} Filter Guest",
            'total_order' => 100,
            'status' => $status,
        ]);

        $this->get(route('orders.index', ['status' => $status]))
            ->assertSee($order->order_id)
            ->assertSeeText($status)
            ->assertSee(
                $status === 'Distribusi'
                    ? 'order-status-tab is-distribution is-active'
                    : 'order-status-tab is-packing is-active',
                false,
            )
            ->assertSee(
                $status === 'Distribusi'
                    ? 'order-status-pill is-distribution'
                    : 'order-status-pill is-packing',
                false,
            );
    }
});

test('factory dashboard counts packing and distribution as active orders', function () {
    foreach (['Packing', 'Distribusi'] as $index => $status) {
        Order::create([
            'order_id' => "DASHBOARD-ACTIVE-{$index}",
            'guest_name' => "Active Order Guest {$index}",
            'total_order' => 100,
            'status' => $status,
        ]);
    }

    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['orders'],
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('admin.index'))
        ->assertSeeInOrder(['Order berjalan', '<strong>2</strong>'], false);
});

test('Stock backfill moves only unstarted items into PB distribution', function () {
    $order = Order::create([
        'order_id' => 'STOCK-BACKFILL',
        'guest_name' => 'Stock Backfill Guest',
        'total_order' => 200,
        'status' => 'None',
    ]);
    $unstartedStock = $order->items()->create([
        'product' => 'Unstarted Stock',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'None',
        'factory_status' => 'None',
    ]);
    $progressedStock = $order->items()->create([
        'product' => 'Progressed Stock',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $migration = require database_path('migrations/2026_11_17_181833_move_existing_stock_items_to_pb_distribution_queue.php');
    $migration->up();

    expect($unstartedStock->fresh()->status)->toBe('Distribusi')
        ->and($unstartedStock->fresh()->factory_status)->toBe('None')
        ->and($progressedStock->fresh()->status)->toBe('Produksi');
});

test('adding a Stock item to an existing order sends it directly to the PB queue', function () {
    Storage::fake('public');
    $guest = Guest::create(['name' => 'Updated Stock Guest']);
    $order = Order::create([
        'order_id' => 'UPDATED-STOCK-ITEM',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 10000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => 'Updated Stock Chair',
        'dimensions' => '10x10x10',
        'qty' => 1,
        'price' => 10000,
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);

    $this->patch(route('orders.update', $order), [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'paid_amount' => 0,
        'items' => [[
            'id' => $item->id,
            'product' => $item->product,
            'dimensions' => $item->dimensions,
            'qty' => $item->qty,
            'price' => $item->price,
            'source' => 'Stock',
            'status' => 'None',
            'status_images' => [
                UploadedFile::fake()->create('stock-inspection.jpg', 100, 'image/jpeg'),
            ],
        ]],
    ])->assertRedirect(route('orders.index'));

    expect($item->fresh()->status)->toBe('Distribusi')
        ->and($item->fresh()->factory_status)->toBe('None');
});

test('order baru menolak order id yang sudah digunakan', function () {
    $existingGuest = Guest::create(['name' => 'Existing Guest']);
    $newGuest = Guest::create(['name' => 'New Guest']);

    Order::create([
        'order_id' => 'ORD-DUPLICATE',
        'guest_id' => $existingGuest->id,
        'guest_name' => 'Existing Guest',
        'total_order' => 100,
        'status' => 'None',
    ]);

    $response = $this->from(route('orders.create'))->post(route('orders.store'), [
        'order_id' => 'ORD-DUPLICATE',
        'guest_id' => $newGuest->id,
        'guest_name' => 'New Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'status' => 'None',
        'items' => [
            [
                'product' => 'Preserved Sofa Product',
                'dimensions' => '10x10x10',
                'qty' => 1,
                'price' => 275000,
                'source' => 'Stock',
                'status' => 'None',
            ],
        ],
    ]);

    $response->assertSessionHasErrors([
        'order_id' => 'SPK sudah digunakan.',
    ]);

    $formResponse = $this->get(route('orders.create'));
    $formResponse->assertSee('ORD-DUPLICATE')
        ->assertSee('New Guest')
        ->assertSee('Preserved Sofa Product');

    expect(Order::where('order_id', 'ORD-DUPLICATE')->count())->toBe(1);
});

test('endpoint pengecekan order id membedakan id yang sudah dan belum dipakai', function () {
    Order::create([
        'order_id' => 'ORD-CHECKED',
        'guest_name' => 'Existing Guest',
        'total_order' => 100,
        'status' => 'None',
    ]);

    $duplicateResponse = $this->getJson(route('orders.check-id', [
        'order_id' => 'ORD-CHECKED',
    ]));

    $duplicateResponse->assertOk()
        ->assertJsonPath('available', false)
        ->assertJsonPath('message', 'SPK sudah digunakan.');

    $availableResponse = $this->getJson(route('orders.check-id', [
        'order_id' => 'ORD-AVAILABLE',
    ]));

    $availableResponse->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('message', null);
});

test('order tidak dapat ditandai selesai manual jika masih ada produk yang belum ready', function () {
    $guest = Guest::create(['name' => 'Guest']);

    $order = Order::create([
        'order_id' => 'ORD-001',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest',
        'guest_source' => 'Lokal',
        'total_order' => 200,
        'status' => 'Proses Produksi',
    ]);

    $readyItem = $order->items()->create([
        'product' => 'Product Ready',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Ready',
    ]);

    $unfinishedItem = $order->items()->create([
        'product' => 'Product In Progress',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Produksi',
    ]);

    $response = $this->put(route('orders.update', $order), [
        'order_id' => 'ORD-001',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest Updated',
        'guest_source' => 'Online',
        'order_type' => 'Ekspor',
        'items' => [
            [
                'id' => $readyItem->id,
                'product' => 'Product Ready',
                'qty' => 1,
                'price' => 100,
                'source' => 'Stock',
                'status' => 'Ready',
            ],
            [
                'id' => $unfinishedItem->id,
                'product' => 'Product In Progress',
                'qty' => 1,
                'price' => 100,
                'source' => 'Order',
                'status' => 'Produksi',
            ],
        ],
    ]);

    $response->assertRedirect(route('orders.index'));

    expect($order->fresh()->status)->toBe('Proses Produksi')
        ->and($order->fresh()->guest_name)->toBe('Guest')
        ->and($unfinishedItem->fresh()->status)->toBe('Produksi');
});

test('status order otomatis menjadi selesai saat semua produk ready', function () {
    $guest = Guest::create(['name' => 'Guest']);

    $order = Order::create([
        'order_id' => 'ORD-002',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest',
        'total_order' => 200,
        'status' => 'Proses Produksi',
    ]);

    $firstItem = $order->items()->create([
        'product' => 'Product One',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Ready',
    ]);

    $secondItem = $order->items()->create([
        'product' => 'Product Two',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Ready',
    ]);

    $response = $this->put(route('orders.update', $order), [
        'order_id' => 'ORD-002',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest',
        'guest_source' => 'Touris',
        'order_type' => 'Bali',
        'status' => 'Selesai',
        'items' => [
            [
                'id' => $firstItem->id,
                'product' => 'Product One',
                'qty' => 1,
                'price' => 100,
                'source' => 'Stock',
                'status' => 'Ready',
            ],
            [
                'id' => $secondItem->id,
                'product' => 'Product Two',
                'qty' => 1,
                'price' => 100,
                'source' => 'Order',
                'status' => 'Ready',
            ],
        ],
    ]);

    $response->assertRedirect(route('orders.index'));
    $response->assertSessionDoesntHaveErrors();

    expect($order->fresh()->status)->toBe('Selesai')
        ->and($order->fresh()->guest_source)->toBe('Touris')
        ->and($firstItem->fresh()->status)->toBe('Ready')
        ->and($secondItem->fresh()->status)->toBe('Ready');
});

test('order Stock berubah menjadi selesai saat semua produknya ready', function () {
    $order = Order::create([
        'order_id' => 'STOCK-ORDER-COMPLETE',
        'guest_name' => 'Stock Completion Guest',
        'total_order' => 100,
        'status' => 'Distribusi',
    ]);
    $order->items()->create([
        'product' => 'Ready Stock Product',
        'qty' => 1,
        'price' => 100,
        'source' => 'Stock',
        'status' => 'Ready',
        'factory_status' => 'Ready',
    ]);

    $order->synchronizeProductionStatusFromItems();

    expect($order->fresh()->status)->toBe('Selesai');
});
