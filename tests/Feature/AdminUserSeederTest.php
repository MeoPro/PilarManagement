<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeded admin can log in', function () {
    config([
        'auth.admin_user' => [
            'name' => 'Admin Seeder',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ],
    ]);

    $this->seed();

    $response = $this->post(route('login.store'), [
        'email' => 'admin@example.com',
        'password' => 'secret-password',
    ]);

    $response->assertRedirect(route('admin.index'));
    $this->assertAuthenticatedAs(User::where('email', 'admin@example.com')->firstOrFail());
    $this->assertDatabaseHas('users', [
        'email' => 'admin@example.com',
        'is_admin' => true,
        'role' => User::ROLE_OWNER,
        'business_unit' => null,
    ]);
});

test('seeding fails when admin credentials are missing', function () {
    config(['auth.admin_user' => []]);

    expect(fn () => $this->seed())
        ->toThrow(RuntimeException::class, 'Set valid ADMIN_USER_NAME, ADMIN_USER_EMAIL, and ADMIN_USER_PASSWORD values before seeding.');
});
