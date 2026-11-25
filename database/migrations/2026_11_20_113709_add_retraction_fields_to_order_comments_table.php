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
        Schema::table('order_comments', function (Blueprint $table) {
            $table->timestamp('retracted_at')->nullable()->after('body');
            $table->foreignId('retracted_by_id')
                ->nullable()
                ->after('retracted_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_comments', function (Blueprint $table) {
            $table->dropForeign(['retracted_by_id']);
            $table->dropColumn(['retracted_at', 'retracted_by_id']);
        });
    }
};
