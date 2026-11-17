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
        Schema::table('piecework_assignments', function (Blueprint $table) {
            $table->unsignedInteger('completed_quantity')->default(0)->after('target_quantity');
            $table->text('completion_note')->nullable()->after('completed_quantity');
            $table->text('cancellation_note')->nullable()->after('cancelled_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('piecework_assignments', function (Blueprint $table) {
            $table->dropColumn(['completed_quantity', 'completion_note', 'cancellation_note']);
        });
    }
};
