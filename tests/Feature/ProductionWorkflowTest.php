<?php

use App\Models\Employee;
use App\Models\FinishingAssignment;
use App\Models\Guest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function workflowImage(string $filename): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $filename,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC'),
    );
}

function createWorkflowOrder(string $orderId, array $itemAttributes = []): array
{
    $guest = Guest::create(['name' => "{$orderId} Guest"]);
    $order = Order::create([
        'order_id' => $orderId,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 1000,
        'status' => 'Proses Produksi',
    ]);
    $item = $order->items()->create(array_merge([
        'product' => 'Workflow Chair',
        'qty' => 2,
        'qty_completed' => 0,
        'price' => 500,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ], $itemAttributes));

    return [$order, $item];
}

test('PF work stays new until every production item has a work drawing', function () {
    Storage::fake('public');
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['orders', 'order_products', 'attendance'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-DRAWING', [
        'status' => 'None',
        'factory_status' => 'None',
    ]);
    $order->update(['status' => 'None']);
    $pendingItem = $order->items()->create([
        'product' => 'Pending New Table',
        'qty' => 1,
        'price' => 500,
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);

    $this->actingAs($salesAdmin)
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $item->id,
                'status' => 'None',
                'status_images' => [workflowImage('mockup.png')],
            ]],
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Produksi')
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and($order->fresh()->status)->toBe('None');

    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Borongan',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText($item->product)
        ->assertSeeText('New')
        ->assertSeeText('Unggah gambar kerja tukang')
        ->assertDontSeeText($pendingItem->product);
    $this->get(route('orders.index'))
        ->assertSeeText($order->order_id)
        ->assertSeeText($item->product)
        ->assertDontSeeText($pendingItem->product)
        ->assertDontSee('Simpan Status');
    $this->get(route('orders.show', $order))
        ->assertDontSee('Perbarui status order')
        ->assertDontSee('Simpan Status')
        ->assertSeeText('Unggah gambar kerja tukang PF')
        ->assertDontSeeText($pendingItem->product);

    $this->actingAs($factoryAdmin)
        ->post(route('order-items.piecework-assignments.store', $item), [
            'employee_id' => $employee->id,
            'target_quantity' => 1,
            'target_deadline' => now()->addDay()->toDateString(),
        ])
        ->assertSessionHasErrors('target_quantity');

    $this->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('drawing-one.png'),
        ])->assertRedirect(route('order-products.index'));

    expect($order->fresh()->status)->toBe('None');
    Storage::disk('public')->assertExists($item->fresh()->factory_drawing_image);
    expect($item->fresh()->factory_status)->toBe('Produksi');
    $this->get(route('order-products.index'))
        ->assertSeeText($item->product);

    $this->actingAs($salesAdmin)
        ->from(route('orders.edit', $order))
        ->patch(route('orders.production.update', $order), [
            'items' => [[
                'id' => $pendingItem->id,
                'status' => 'None',
                'status_images' => [workflowImage('pending-table-mockup.png')],
            ]],
        ])
        ->assertRedirect(route('orders.edit', $order));

    expect($order->fresh()->status)->toBe('Proses Produksi')
        ->and($pendingItem->fresh()->factory_status)->toBe('None');

    $this->actingAs($factoryAdmin)
        ->get(route('order-products.index'))
        ->assertSeeText($pendingItem->product)
        ->assertSeeText('Unggah gambar kerja tukang');

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $pendingItem), [
            'factory_drawing_image' => workflowImage('drawing-two.png'),
        ])
        ->assertRedirect(route('order-products.index'));

    expect($pendingItem->fresh()->factory_drawing_image)->not->toBeNull()
        ->and($pendingItem->fresh()->factory_status)->toBe('Produksi')
        ->and($order->fresh()->status)->toBe('Proses Produksi');
    Storage::disk('public')->assertExists($pendingItem->fresh()->factory_drawing_image);
});

test('PF cannot upload a work drawing before PB starts production', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['order_products'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-DRAWING-TOO-EARLY', [
        'status' => 'None',
        'factory_status' => 'None',
    ]);
    $order->update(['status' => 'None']);

    $this->actingAs($factoryAdmin)
        ->from(route('order-products.index'))
        ->patch(route('order-products.factory-drawing.update', $item), [
            'factory_drawing_image' => workflowImage('too-early.png'),
        ])
        ->assertSessionHasErrors('factory_drawing_image');

    expect($item->fresh()->factory_drawing_image)->toBeNull()
        ->and($item->fresh()->factory_status)->toBe('None')
        ->and($order->fresh()->status)->toBe('None');
    $this->assertDatabaseCount('order_events', 0);
});

test('Factory finishing moves partial quantities to packing and quality checks finish the order', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['order_products', 'orders'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-FINISHING', [
        'qty_completed' => 2,
        'factory_status' => 'Finishing',
        'status' => 'Finishing',
        'factory_drawing_image' => 'orders/factory-drawings/chair.png',
    ]);
    Storage::disk('public')->put('orders/factory-drawings/chair.png', 'drawing');
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);

    $this->actingAs($factoryAdmin)
        ->post(route('admin.finishing-assignments.store'), [
            'employee_id' => $employee->id,
            'order_item_id' => $item->id,
            'target_quantity' => 2,
            'is_rework' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Tugas finishing Harian dimulai dan tidak menambah upah harian.');

    $assignment = FinishingAssignment::query()->firstOrFail();
    expect($assignment->started_at)->not->toBeNull();

    $this->patch(route('admin.finishing-assignments.complete', $assignment), [
        'quantity' => 1,
        'note' => 'Satu unit selesai',
    ])->assertRedirect();

    expect((int) $item->fresh()->packed_quantity)->toBe(1)
        ->and($assignment->fresh()->remaining_quantity)->toBe(1);

    $this->patch(route('admin.finishing-assignments.complete', $assignment), [
        'quantity' => 1,
        'note' => 'Sisa unit selesai',
    ])->assertRedirect();

    expect((int) $item->fresh()->packed_quantity)->toBe(2)
        ->and($assignment->fresh()->completed_at)->not->toBeNull()
        ->and($employee->attendances()->count())->toBe(0);

    $this->actingAs($salesAdmin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Bagus',
            'quantity' => 1,
            'note' => 'Lolos QC',
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Dalam Perjalanan')
        ->and((int) $item->fresh()->quality_good_quantity)->toBe(1)
        ->and((int) $item->fresh()->packed_quantity)->toBe(1);

    $this->post(route('order-products.quality.store', $item), [
        'result' => 'Bagus',
        'quantity' => 1,
        'note' => 'Lolos QC',
    ])->assertRedirect();

    expect($item->fresh()->status)->toBe('Ready')
        ->and($order->fresh()->status)->toBe('Selesai');
});

test('damaged products return to Factory rework and shortage can complete quality status', function () {
    Storage::fake('public');
    $factoryAdmin = User::factory()->factoryAdmin()->create([
        'accessible_menus' => ['attendance', 'order_products', 'orders'],
    ]);
    $salesAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['order_products', 'orders'],
    ]);
    [$order, $item] = createWorkflowOrder('WORKFLOW-QC', [
        'qty_completed' => 2,
        'packed_quantity' => 2,
        'factory_status' => 'Packing',
        'status' => 'Dalam Perjalanan',
        'factory_drawing_image' => 'orders/factory-drawings/qc.png',
    ]);
    Storage::disk('public')->put('orders/factory-drawings/qc.png', 'drawing');

    $this->actingAs($salesAdmin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Rusak',
            'quantity' => 1,
            'note' => 'Sambungan rusak',
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe('Finishing')
        ->and((int) $item->fresh()->quality_damaged_quantity)->toBe(1);

    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'employee_type' => 'Harian',
    ]);
    $this->actingAs($factoryAdmin)
        ->post(route('admin.finishing-assignments.store'), [
            'employee_id' => $employee->id,
            'order_item_id' => $item->id,
            'target_quantity' => 1,
            'is_rework' => 1,
        ])
        ->assertRedirect();

    $rework = FinishingAssignment::query()->firstOrFail();
    $this->patch(route('admin.finishing-assignments.complete', $rework), [
        'quantity' => 1,
        'note' => 'Perbaikan selesai',
    ])->assertRedirect();

    expect((int) $item->fresh()->quality_damaged_quantity)->toBe(0)
        ->and((int) $item->fresh()->packed_quantity)->toBe(2)
        ->and($item->fresh()->status)->toBe('Dalam Perjalanan');

    $this->actingAs($salesAdmin)
        ->post(route('order-products.quality.store', $item), [
            'result' => 'Bagus',
            'quantity' => 1,
        ])
        ->assertRedirect();

    $this->post(route('order-products.quality.store', $item), [
        'result' => 'Kurang',
        'quantity' => 1,
        'note' => 'Service Bali',
    ])->assertRedirect();

    expect($item->fresh()->status)->toBe('Ready')
        ->and((int) $item->fresh()->quality_good_quantity)->toBe(1)
        ->and((int) $item->fresh()->quality_short_quantity)->toBe(1)
        ->and($order->fresh()->status)->toBe('Selesai');
});
