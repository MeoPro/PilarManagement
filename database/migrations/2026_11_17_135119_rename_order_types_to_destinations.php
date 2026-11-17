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
        DB::table('orders')->where('order_type', 'Lokal')->update(['order_type' => 'Bali']);
        DB::table('orders')->where('order_type', 'Luar Kota')->update(['order_type' => 'Luar Bali']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->where('order_type', 'Bali')->update(['order_type' => 'Lokal']);
        DB::table('orders')->where('order_type', 'Luar Bali')->update(['order_type' => 'Luar Kota']);
    }
};
