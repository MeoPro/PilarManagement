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
        Schema::table('products', function (Blueprint $table) {
            $table->string('dimensions', 100)->nullable()->after('price');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('dimensions', 100)->nullable()->after('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('dimensions');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('dimensions');
        });
    }
};
