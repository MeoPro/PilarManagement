<?php

use App\Models\Color;
use App\Models\Guest;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
        ->assertSee('Service');
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
            'phone' => $phone,
            'status' => 'None',
            'items' => [[
                'product' => 'Product '.$orderId,
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
            'status' => 'Produksi',
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

test('form order baru tidak menyediakan pilihan status order', function () {
    $response = $this->get(route('orders.create'));

    $response->assertSee('Status Order')
        ->assertSee('Service')
        ->assertSee('Sumber Tamu')
        ->assertSee('Lokal')
        ->assertSee('Online')
        ->assertSee('Touris')
        ->assertSee('None')
        ->assertDontSee('<select name="status">', false);
});

test('order baru selalu dibuat dengan status none', function () {
    $guest = Guest::create(['name' => 'Guest']);

    $response = $this->post(route('orders.store'), [
        'order_id' => 'ORD-003',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest',
        'guest_source' => 'Online',
        'status' => 'Selesai',
        'items' => [
            [
                'product' => 'Product One',
                'qty' => 1,
                'price' => 100,
                'source' => 'Stock',
                'status' => 'Produksi',
            ],
        ],
    ]);

    $response->assertRedirect(route('orders.index'));
    $order = Order::where('order_id', 'ORD-003')->firstOrFail();

    expect($order->status)->toBe('None')
        ->and($order->guest_source)->toBe('Online');
});

test('order menerima dan menyimpan sumber produk service', function () {
    $guest = Guest::create(['name' => 'Service Guest']);

    $response = $this->post(route('orders.store'), [
        'order_id' => 'ORD-SERVICE',
        'guest_id' => $guest->id,
        'guest_name' => 'Service Guest',
        'guest_source' => 'Lokal',
        'status' => 'None',
        'items' => [
            [
                'product' => 'Room Service',
                'qty' => 1,
                'price' => 50000,
                'source' => 'Service',
                'status' => 'None',
            ],
        ],
    ]);

    $response->assertRedirect(route('orders.index'));

    $this->assertDatabaseHas('order_items', [
        'product' => 'Room Service',
        'source' => 'Service',
    ]);
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
        'status' => 'None',
        'items' => [
            [
                'product' => 'Preserved Sofa Product',
                'qty' => 1,
                'price' => 275000,
                'source' => 'Stock',
                'status' => 'None',
            ],
        ],
    ]);

    $response->assertSessionHasErrors([
        'order_id' => 'Order ID sudah digunakan.',
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
        ->assertJsonPath('message', 'Order ID sudah digunakan.');

    $availableResponse = $this->getJson(route('orders.check-id', [
        'order_id' => 'ORD-AVAILABLE',
    ]));

    $availableResponse->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('message', null);
});

test('order tidak bisa diselesaikan jika masih ada produk yang belum ready', function () {
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
        'status' => 'Selesai',
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

    $response->assertSessionHasErrors([
        'status' => 'Order tidak bisa diselesaikan karena semua produk harus berstatus Ready.',
    ]);

    expect($order->fresh()->status)->toBe('Proses Produksi')
        ->and($order->fresh()->guest_name)->toBe('Guest')
        ->and($unfinishedItem->fresh()->status)->toBe('Produksi');
});

test('order bisa diselesaikan jika semua produk pada perubahan berstatus ready', function () {
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
        'status' => 'Produksi',
    ]);

    $secondItem = $order->items()->create([
        'product' => 'Product Two',
        'qty' => 1,
        'price' => 100,
        'source' => 'Order',
        'status' => 'Distribusi',
    ]);

    $response = $this->put(route('orders.update', $order), [
        'order_id' => 'ORD-002',
        'guest_id' => $guest->id,
        'guest_name' => 'Guest',
        'guest_source' => 'Touris',
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
