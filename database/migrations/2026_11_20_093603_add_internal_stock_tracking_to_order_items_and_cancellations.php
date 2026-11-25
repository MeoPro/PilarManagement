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
            $table->unsignedInteger('stock_added_quantity')
                ->default(0)
                ->after('stock_received_quantity');
        });

        Schema::table('order_item_cancellations', function (Blueprint $table) {
            $table->string('stock_business_unit')->nullable()->after('stock_factory_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_item_cancellations', function (Blueprint $table) {
            $table->dropColumn('stock_business_unit');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('stock_added_quantity');
        });
    }
};
