<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('factory_price_set_at')->nullable()->index();
            $table->timestamp('pb_accepted_at')->nullable()->index();
        });

        DB::table('order_items')
            ->whereNotNull('factory_sale_price')
            ->update([
                'factory_price_set_at' => DB::raw('COALESCE(factory_handover_at, created_at)'),
            ]);

        DB::table('order_items')
            ->whereNotNull('factory_handover_at')
            ->where(function ($query): void {
                $query->where('quality_good_quantity', '>', 0)
                    ->orWhere('status', 'Ready');
            })
            ->update([
                'pb_accepted_at' => DB::raw('COALESCE(updated_at, factory_handover_at, created_at)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['factory_price_set_at']);
            $table->dropIndex(['pb_accepted_at']);
            $table->dropColumn(['factory_price_set_at', 'pb_accepted_at']);
        });
    }
};
