<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUser = config('auth.admin_user', []);

        if (
            ! is_array($adminUser)
            || ! is_string($adminUser['name'] ?? null)
            || trim($adminUser['name']) === ''
            || ! is_string($adminUser['email'] ?? null)
            || filter_var(trim($adminUser['email']), FILTER_VALIDATE_EMAIL) === false
            || ! is_string($adminUser['password'] ?? null)
            || $adminUser['password'] === ''
        ) {
            throw new RuntimeException(
                'Set valid ADMIN_USER_NAME, ADMIN_USER_EMAIL, and ADMIN_USER_PASSWORD values before seeding.'
            );
        }

        $user = User::firstOrNew(['email' => trim($adminUser['email'])]);
        $user->name = trim($adminUser['name']);
        $user->password = $adminUser['password'];
        $user->is_admin = true;
        $user->role = User::ROLE_OWNER;
        $user->business_unit = null;
        $user->is_active = true;
        $user->save();
    }
}
