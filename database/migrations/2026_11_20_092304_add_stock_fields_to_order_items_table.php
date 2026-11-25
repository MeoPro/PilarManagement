<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('stock_business_unit')->nullable();
            $table->unsignedInteger('stock_bali_quantity')->default(0);
            $table->unsignedInteger('stock_factory_quantity')->default(0);
            $table->unsignedInteger('stock_received_quantity')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'stock_business_unit',
                'stock_bali_quantity',
                'stock_factory_quantity',
                'stock_received_quantity',
            ]);
        });
    }
};
