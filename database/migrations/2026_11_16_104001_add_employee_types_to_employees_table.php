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
        Schema::table('employees', function (Blueprint $table) {
            $table->json('employee_types')->nullable();
        });

        DB::table('employees')
            ->select(['id', 'employee_type'])
            ->orderBy('id')
            ->each(function (object $employee): void {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update([
                        'employee_types' => json_encode([$employee->employee_type ?: 'Harian'], JSON_THROW_ON_ERROR),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employee_types');
        });
    }
};
