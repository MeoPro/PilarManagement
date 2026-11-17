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
        Schema::table('guests', function (Blueprint $table) {
            $table->string('guest_source')->nullable()->after('name');
        });

        DB::table('orders')
            ->select(['guest_id', 'guest_source'])
            ->whereNotNull('guest_id')
            ->whereNotNull('guest_source')
            ->where('guest_source', '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->chunk(500, function ($orders): void {
                foreach ($orders->unique('guest_id') as $order) {
                    DB::table('guests')
                        ->where('id', $order->guest_id)
                        ->whereNull('guest_source')
                        ->update(['guest_source' => $order->guest_source]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('guest_source');
        });
    }
};
