<?php

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owner records expenses per unit and the server calculates the total', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('expenses.create'))
        ->assertOk();

    $this->actingAs($owner)
        ->post(route('expenses.store'), [
            'business_unit' => User::UNIT_FACTORY,
            'description' => 'Bensin harian',
            'quantity' => 2.5,
            'unit' => 'liter',
            'unit_price' => '10,000',
            'total' => 1,
            'occurred_at' => '2026-03-15',
        ])
        ->assertRedirect(route('expenses.index'));

    $expense = Expense::query()->where('description', 'Bensin harian')->firstOrFail();
    expect((float) $expense->total)->toBe(25000.0)
        ->and($expense->business_unit)->toBe(User::UNIT_FACTORY);
    $this->get(route('expenses.show', $expense))->assertOk();
    $this->get(route('expenses.edit', $expense))->assertOk();

    $this->actingAs($owner)
        ->put(route('expenses.update', $expense), [
            'description' => 'Bensin dan parkir',
            'quantity' => 3,
            'unit' => 'kali',
            'unit_price' => '12,500',
            'total' => 2,
            'occurred_at' => '2026-03-16',
        ])
        ->assertRedirect(route('expenses.show', $expense));

    expect((float) $expense->fresh()->total)->toBe(37500.0);
});

test('expense filters default the end date to today and exclude future expenses', function () {
    $this->travelTo('2026-11-17 10:00:00');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['expenses'],
    ]);
    Expense::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'description' => 'Pengeluaran kemarin',
        'occurred_at' => '2026-11-16',
    ]);
    Expense::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'description' => 'Pengeluaran hari ini',
        'occurred_at' => '2026-11-17',
    ]);
    Expense::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'description' => 'Pengeluaran besok',
        'occurred_at' => '2026-11-18',
    ]);

    $this->actingAs($admin)
        ->get(route('expenses.index'))
        ->assertSee('name="to" value="2026-11-17"', false)
        ->assertSeeText('Pengeluaran kemarin')
        ->assertSeeText('Pengeluaran hari ini')
        ->assertDontSeeText('Pengeluaran besok');
});

test('expense records are isolated to the admin business unit', function () {
    $baliAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['expenses'],
    ]);
    $factoryExpense = Expense::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'description' => 'Biaya Factory',
    ]);
    Expense::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'description' => 'Biaya Bali',
    ]);

    $this->actingAs($baliAdmin)
        ->get(route('expenses.index'))
        ->assertSeeText('Biaya Bali')
        ->assertDontSeeText('Biaya Factory');

    $this->get(route('expenses.show', $factoryExpense))->assertNotFound();

    $this->post(route('expenses.store'), [
        'business_unit' => User::UNIT_FACTORY,
        'description' => 'Pengeluaran admin Bali',
        'quantity' => 1,
        'unit_price' => 100,
        'occurred_at' => '2026-03-15',
    ])->assertRedirect();

    $this->assertDatabaseHas('expenses', [
        'description' => 'Pengeluaran admin Bali',
        'business_unit' => User::UNIT_BALI,
    ]);
});
