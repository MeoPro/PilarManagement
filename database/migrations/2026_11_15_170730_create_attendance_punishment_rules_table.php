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
        Schema::create('attendance_punishment_rules', function (Blueprint $table) {
            $table->id();
            $table->string('applies_to')->default('all');
            $table->decimal('attendance_below', 5, 2);
            $table->text('punishment');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'applies_to', 'attendance_below']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_punishment_rules');
    }
};
