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
        Schema::create('piecework_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('target_quantity');
            $table->date('target_deadline');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'completed_at', 'target_deadline']);
            $table->index(['order_item_id', 'completed_at']);
        });

        Schema::table('piecework_items', function (Blueprint $table) {
            $table->foreignId('piecework_assignment_id')
                ->nullable()
                ->after('employee_attendance_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['piecework_assignment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('piecework_items', function (Blueprint $table) {
            $table->dropIndex(['piecework_assignment_id', 'created_at']);
            $table->dropConstrainedForeignId('piecework_assignment_id');
        });

        Schema::dropIfExists('piecework_assignments');
    }
};
