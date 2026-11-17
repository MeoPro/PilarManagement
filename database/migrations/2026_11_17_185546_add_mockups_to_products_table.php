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
        Schema::table('products', function (Blueprint $table): void {
            $table->json('mockups')->nullable()->after('mockup');
        });

        DB::table('products')
            ->whereNotNull('mockup')
            ->orderBy('id')
            ->get(['id', 'mockup'])
            ->each(function (object $product): void {
                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['mockups' => json_encode([$product->mockup], JSON_THROW_ON_ERROR)]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('mockups');
        });
    }
};
