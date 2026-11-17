<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create-user {name} {email} {unit=bali}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an administrator account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = trim((string) $this->argument('name'));
        $unit = (string) $this->argument('unit');

        if (
            $name === ''
            || mb_strlen($name) > 255
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! in_array($unit, [User::UNIT_BALI, User::UNIT_FACTORY], true)
        ) {
            $this->error('Nama dan email harus valid serta unit harus bali atau factory.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('Email tersebut sudah digunakan.');

            return self::FAILURE;
        }

        $password = $this->secret('Password');
        $confirmation = $this->secret('Ulangi password');

        if (! is_string($password) || $password === '' || $password !== $confirmation) {
            $this->error('Password wajib diisi dan harus sama.');

            return self::FAILURE;
        }

        $admin = new User([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $admin->is_admin = true;
        $admin->role = User::ROLE_ADMIN;
        $admin->business_unit = $unit;
        $admin->is_active = true;
        $admin->save();

        $this->info('Akun admin berhasil dibuat.');

        return self::SUCCESS;
    }
}
