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
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('factory_sale_price', 15, 2)->nullable();
                $table->timestamp('factory_handover_at')->nullable()->index();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropIndex(['factory_handover_at']);
                $table->dropColumn(['factory_sale_price', 'factory_handover_at']);
            });
        });
    }
};
