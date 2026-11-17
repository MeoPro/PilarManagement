<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('order_items')
            ->where('status', 'Distribusi')
            ->whereColumn('qty_completed', '<', 'qty')
            ->update(['qty_completed' => DB::raw('qty')]);
    }

    /**
     * Keep production progress if this migration is rolled back.
     */
    public function down(): void {}
};
