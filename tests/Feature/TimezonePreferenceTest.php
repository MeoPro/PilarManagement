<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owner can save a WIB preference and sees the selected timezone', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->put(route('admin.timezone-preferences.update'), [
            'timezone_preference' => 'Asia/Jakarta',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Zona waktu berhasil diperbarui.');

    $this->assertDatabaseHas('users', [
        'id' => $owner->id,
        'timezone_preference' => 'Asia/Jakarta',
    ]);

    $this->get(route('admin.index'))
        ->assertSee('WIB')
        ->assertSee('value="Asia/Jakarta" selected', false);
});

test('Factory admins always use WIB regardless of a saved preference', function () {
    $admin = User::factory()->factoryAdmin()->create([
        'timezone_preference' => 'Asia/Makassar',
    ]);

    expect($admin->timezone())->toBe('Asia/Jakarta')
        ->and($admin->timezoneLabel())->toBe('WIB');

    $this->actingAs($admin)
        ->get(route('admin.index'))
        ->assertSee('WIB')
        ->assertDontSee('value="Asia/Makassar"', false);
});

test('invalid timezone preferences are rejected without changing the saved value', function () {
    $owner = User::factory()->owner()->create([
        'timezone_preference' => 'Asia/Makassar',
    ]);

    $this->actingAs($owner)
        ->from(route('admin.index'))
        ->put(route('admin.timezone-preferences.update'), [
            'timezone_preference' => 'UTC',
        ])
        ->assertRedirect(route('admin.index'))
        ->assertSessionHasErrors('timezone_preference');

    $this->assertDatabaseHas('users', [
        'id' => $owner->id,
        'timezone_preference' => 'Asia/Makassar',
    ]);
});
