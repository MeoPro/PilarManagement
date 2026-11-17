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
        Schema::table('piecework_items', function (Blueprint $table) {
            $table->foreignId('order_item_id')
                ->nullable()
                ->after('employee_attendance_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['order_item_id', 'created_at']);
        });

        Schema::table('subcontractor_assignments', function (Blueprint $table) {
            $table->date('target_deadline')->nullable()->after('assigned_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subcontractor_assignments', function (Blueprint $table) {
            $table->dropColumn('target_deadline');
        });

        Schema::table('piecework_items', function (Blueprint $table) {
            $table->dropIndex(['order_item_id', 'created_at']);
            $table->dropConstrainedForeignId('order_item_id');
        });
    }
};
