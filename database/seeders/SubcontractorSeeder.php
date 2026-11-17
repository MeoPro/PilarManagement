<?php

namespace Database\Seeders;

use App\Models\Subcontractor;
use Illuminate\Database\Seeder;

class SubcontractorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Subcontractor::firstOrCreate(
            ['name' => 'Contoh Subkontraktor'],
            [
                'phone' => '081234567890',
                'email' => 'subcon@example.com',
                'address' => 'Alamat contoh',
                'is_active' => true,
            ],
        );
    }
}
