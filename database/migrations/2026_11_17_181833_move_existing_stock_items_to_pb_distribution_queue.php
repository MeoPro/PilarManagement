<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_items')
            ->where('source', 'Stock')
            ->where('status', 'None')
            ->where('factory_status', 'None')
            ->where('qty_completed', 0)
            ->update([
                'status' => 'Distribusi',
                'updated_at' => now(),
            ]);
    }

    /**
     * Existing product statuses cannot be safely reverted after PB progress.
     */
    public function down(): void {}
};
