<?php

use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owner creates material with opening stock and stock ledger prevents negative balance', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('materials.create'))
        ->assertOk()
        ->assertSeeText('Stok Awal');

    $this->from(route('materials.create'))
        ->post(route('materials.store'), [
            'business_unit' => User::UNIT_BALI,
            'name' => 'Kayu Tanpa Stok',
            'unit' => 'lembar',
            'unit_price' => '100,000',
        ])
        ->assertSessionHasErrors('initial_stock');

    $this->assertDatabaseMissing('materials', ['name' => 'Kayu Tanpa Stok']);

    $this->actingAs($owner)
        ->post(route('materials.store'), [
            'business_unit' => User::UNIT_BALI,
            'name' => 'Kayu Jati',
            'unit' => 'lembar',
            'unit_price' => '100,000',
            'initial_stock' => 5,
        ])
        ->assertRedirect();

    $material = Material::query()->where('name', 'Kayu Jati')->firstOrFail();
    $this->get(route('materials.edit', $material))->assertOk();
    $this->assertDatabaseHas('material_transactions', [
        'material_id' => $material->id,
        'type' => 'in',
        'quantity' => 5,
        'unit_price' => 100000,
        'note' => 'Stok awal',
    ]);

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'out',
        'quantity' => 2,
        'note' => 'Produksi kursi',
    ])->assertRedirect(route('materials.show', $material));

    $this->from(route('materials.show', $material))
        ->post(route('materials.transactions.store', $material), [
            'type' => 'out',
            'quantity' => 4,
        ])
        ->assertSessionHasErrors('quantity');

    $this->get(route('materials.show', $material))
        ->assertSeeText('3 lembar')
        ->assertSeeText('Produksi kursi');

    $this->assertDatabaseCount('material_transactions', 2);
});

test('admins can see and update materials only for their own unit', function () {
    $baliAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['materials'],
    ]);
    $baliMaterial = Material::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'name' => 'Material Bali',
    ]);
    $factoryMaterial = Material::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'name' => 'Material Factory',
    ]);
    $factoryTransaction = $factoryMaterial->transactions()->create([
        'type' => 'in',
        'quantity' => 1,
        'unit_price' => 500,
        'occurred_at' => now(),
    ]);

    $this->actingAs($baliAdmin)
        ->get(route('materials.index'))
        ->assertSeeText('Material Bali')
        ->assertDontSeeText('Material Factory');

    $this->get(route('materials.show', $factoryMaterial))->assertNotFound();

    $this->post(route('materials.transactions.store', $factoryMaterial), [
        'type' => 'in',
        'quantity' => 1,
        'unit_price' => 500,
    ])->assertNotFound();
    $this->patch(route('materials.transactions.update', [$factoryMaterial, $factoryTransaction]), [
        'quantity' => 2,
        'unit_price' => 500,
    ])->assertNotFound();

    $this->post(route('materials.store'), [
        'business_unit' => User::UNIT_FACTORY,
        'name' => 'Material Baru Bali',
        'unit' => 'kg',
        'unit_price' => 250,
        'initial_stock' => 1,
    ])->assertRedirect();

    $this->assertDatabaseHas('materials', [
        'name' => 'Material Baru Bali',
        'business_unit' => User::UNIT_BALI,
    ]);
    $this->assertDatabaseMissing('materials', [
        'name' => 'Material Baru Bali',
        'business_unit' => User::UNIT_FACTORY,
    ]);
    $this->assertDatabaseHas('materials', ['id' => $baliMaterial->id]);
});

test('material purchases are expenses and material usage is valued at weighted average cost', function () {
    $this->travelTo('2026-11-18 10:00:00');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['materials', 'revenue'],
    ]);

    $this->actingAs($admin)
        ->post(route('materials.store'), [
            'name' => 'Kayu Campur',
            'unit' => 'lembar',
            'unit_price' => '10,000',
            'initial_stock' => 10,
        ])
        ->assertRedirect();

    $material = Material::query()->where('name', 'Kayu Campur')->firstOrFail();

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'in',
        'quantity' => 10,
        'unit_price' => '20,000',
        'occurred_at' => '2026-11-18T10:00',
        'note' => 'Pembelian harga kedua',
    ])->assertRedirect(route('materials.show', $material));

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'out',
        'quantity' => 10,
        'occurred_at' => '2026-11-18T11:00',
        'note' => 'Dipakai produksi',
    ])->assertRedirect(route('materials.show', $material));

    $this->assertDatabaseHas('material_transactions', [
        'material_id' => $material->id,
        'type' => 'out',
        'quantity' => 10,
        'unit_price' => 15000,
        'note' => 'Dipakai produksi',
    ]);

    $this->get(route('materials.index'))
        ->assertSeeText('10 lembar')
        ->assertDontSeeText('10,000 lembar')
        ->assertSeeText('Kayu Campur')
        ->assertDontSee('Kayu Campur <span>', false)
        ->assertSeeText('Rp 15.000,00')
        ->assertSeeText('Detail')
        ->assertSeeText('Masuk')
        ->assertSeeText('Keluar')
        ->assertSeeText('Edit')
        ->assertSeeText('+ Tambah Material')
        ->assertSee(
            route('materials.show', ['material' => $material, 'type' => 'in']).'#transaction-type',
            false,
        )
        ->assertSee(
            route('materials.show', ['material' => $material, 'type' => 'out']).'#transaction-type',
            false,
        );
    $this->get(route('materials.show', ['material' => $material, 'type' => 'out']))
        ->assertSee('value="out" selected', false);

    $this->get(route('revenue.index', [
        'from' => '2026-11-01',
        'to' => '2026-11-18',
    ]))
        ->assertSeeText('Pembelian material')
        ->assertSeeText('Kayu Campur')
        ->assertDontSeeText('Dipakai produksi')
        ->assertViewHas('materialPurchaseTotal', 300000.0)
        ->assertViewHas('totalOutgoing', 300000.0)
        ->assertViewHas('netCashFlow', -300000.0);
});

test('editing a purchase updates revenue and recalculates later stock-out cost', function () {
    $this->travelTo('2026-11-18 10:00:00');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['materials', 'revenue'],
    ]);
    $this->actingAs($admin)
        ->post(route('materials.store'), [
            'name' => 'Material Koreksi Pembelian',
            'unit' => 'pack',
            'unit_price' => '10,000',
            'initial_stock' => 10,
        ])
        ->assertRedirect();
    $material = Material::query()->where('name', 'Material Koreksi Pembelian')->firstOrFail();

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'in',
        'quantity' => 10,
        'unit_price' => '20,000',
        'occurred_at' => '2026-11-18T10:00',
    ])->assertRedirect();
    $purchase = $material->transactions()->where('type', 'in')->latest('id')->firstOrFail();

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'out',
        'quantity' => 10,
        'occurred_at' => '2026-11-18T11:00',
    ])->assertRedirect();
    $usage = $material->transactions()->where('type', 'out')->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('materials.transactions.update', [$material, $purchase]), [
            'quantity' => 12,
            'unit_price' => '25,000',
        ])
        ->assertRedirect(route('materials.show', $material));

    $this->assertDatabaseHas('material_transactions', [
        'id' => $purchase->id,
        'quantity' => 12,
        'unit_price' => 25000,
    ]);
    $this->assertDatabaseHas('material_transactions', [
        'id' => $usage->id,
        'unit_price' => 18181.82,
    ]);

    $this->get(route('materials.index'))
        ->assertSeeText('12 pack')
        ->assertSeeText('Rp 18.181,82');

    $this->get(route('revenue.index', [
        'from' => '2026-11-01',
        'to' => '2026-11-18',
    ]))->assertViewHas('materialPurchaseTotal', 400000.0);
});

test('editing stock-out quantity recalculates later usage and rejects negative history', function () {
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['materials'],
    ]);
    $otherMaterial = Material::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'name' => 'Material Transaksi Lain',
    ]);
    $otherTransaction = $otherMaterial->transactions()->create([
        'type' => 'in',
        'quantity' => 1,
        'unit_price' => 10000,
        'occurred_at' => now(),
    ]);
    $this->actingAs($admin)
        ->post(route('materials.store'), [
            'name' => 'Material Koreksi Keluar',
            'unit' => 'pcs',
            'unit_price' => 10000,
            'initial_stock' => 10,
        ])
        ->assertRedirect();
    $material = Material::query()->where('name', 'Material Koreksi Keluar')->firstOrFail();

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'out',
        'quantity' => 2,
    ])->assertRedirect();
    $firstUsage = $material->transactions()->where('type', 'out')->firstOrFail();

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'out',
        'quantity' => 3,
    ])->assertRedirect();
    $secondUsage = $material->transactions()->where('type', 'out')->latest('id')->firstOrFail();

    $this->get(route('materials.transactions.edit', [$material, $firstUsage]))
        ->assertOk()
        ->assertSeeText('dihitung ulang otomatis')
        ->assertDontSee('name="unit_price"', false);
    $this->get(route('materials.transactions.edit', [$material, $otherTransaction]))
        ->assertNotFound();

    $this->patch(route('materials.transactions.update', [$material, $firstUsage]), [
        'quantity' => 4,
    ])->assertRedirect(route('materials.show', $material));

    $this->assertDatabaseHas('material_transactions', [
        'id' => $firstUsage->id,
        'quantity' => 4,
    ]);
    $this->assertDatabaseHas('material_transactions', [
        'id' => $secondUsage->id,
        'quantity' => 3,
        'unit_price' => 10000,
    ]);
    $this->get(route('materials.show', $material))->assertSeeText('3 pcs');

    $this->from(route('materials.transactions.edit', [$material, $firstUsage]))
        ->patch(route('materials.transactions.update', [$material, $firstUsage]), [
            'quantity' => 8,
        ])
        ->assertSessionHasErrors('quantity');

    $this->assertDatabaseHas('material_transactions', [
        'id' => $firstUsage->id,
        'quantity' => 4,
    ]);
});
