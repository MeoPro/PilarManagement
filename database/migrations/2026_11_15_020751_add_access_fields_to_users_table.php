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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user');
            $table->string('business_unit')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('users')
            ->where('is_admin', true)
            ->update([
                'role' => 'admin',
                'business_unit' => 'bali',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'business_unit', 'is_active']);
        });
    }
};
