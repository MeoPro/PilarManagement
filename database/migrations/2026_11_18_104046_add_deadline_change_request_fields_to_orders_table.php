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
        Schema::table('orders', function (Blueprint $table) {
            $table->date('pending_deadline')->nullable()->after('deadline');
            $table->text('pending_deadline_reason')->nullable()->after('pending_deadline');
            $table->foreignId('pending_deadline_requested_by')
                ->nullable()
                ->after('pending_deadline_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('pending_deadline_requested_at')->nullable()->after('pending_deadline_requested_by');
            $table->date('factory_deadline')->nullable()->after('pending_deadline_requested_at');
            $table->timestamp('factory_started_at')->nullable()->after('factory_deadline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['pending_deadline_requested_by']);
            $table->dropColumn([
                'pending_deadline',
                'pending_deadline_reason',
                'pending_deadline_requested_by',
                'pending_deadline_requested_at',
                'factory_deadline',
                'factory_started_at',
            ]);
        });
    }
};
