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
        ->assertSeeText('3,000 lembar')
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
            'unit_price' => '100,000',
            'initial_stock' => 2,
        ])
        ->assertRedirect();

    $material = Material::query()->where('name', 'Kayu Campur')->firstOrFail();

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'in',
        'quantity' => 2,
        'unit_price' => '200,000',
        'occurred_at' => '2026-11-18T10:00',
        'note' => 'Pembelian harga kedua',
    ])->assertRedirect(route('materials.show', $material));

    $this->post(route('materials.transactions.store', $material), [
        'type' => 'out',
        'quantity' => 2,
        'occurred_at' => '2026-11-18T11:00',
        'note' => 'Dipakai produksi',
    ])->assertRedirect(route('materials.show', $material));

    $this->assertDatabaseHas('material_transactions', [
        'material_id' => $material->id,
        'type' => 'out',
        'quantity' => 2,
        'unit_price' => 150000,
        'note' => 'Dipakai produksi',
    ]);

    $this->get(route('revenue.index', [
        'from' => '2026-11-01',
        'to' => '2026-11-18',
    ]))
        ->assertSeeText('Pembelian material')
        ->assertSeeText('Kayu Campur')
        ->assertDontSeeText('Dipakai produksi')
        ->assertViewHas('materialPurchaseTotal', 600000.0)
        ->assertViewHas('totalOutgoing', 600000.0)
        ->assertViewHas('netCashFlow', -600000.0);
});
