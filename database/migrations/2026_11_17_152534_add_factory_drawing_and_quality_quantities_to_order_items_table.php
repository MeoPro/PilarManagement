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
            $table->string('factory_drawing_image')->nullable();
            $table->unsignedInteger('packed_quantity')->default(0);
            $table->unsignedInteger('quality_good_quantity')->default(0);
            $table->unsignedInteger('quality_damaged_quantity')->default(0);
            $table->unsignedInteger('quality_short_quantity')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'factory_drawing_image',
                'packed_quantity',
                'quality_good_quantity',
                'quality_damaged_quantity',
                'quality_short_quantity',
            ]);
        });
    }
};
