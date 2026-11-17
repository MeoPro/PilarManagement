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
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->string('work_type')->nullable()->after('session');
        });

        DB::table('employee_attendances')
            ->whereIn('employee_id', DB::table('employees')->select('id')->whereNotNull('employee_type'))
            ->update([
                'work_type' => DB::raw(
                    '(SELECT employee_type FROM employees WHERE employees.id = employee_attendances.employee_id)'
                ),
            ]);

        DB::table('employee_attendances')
            ->whereNull('work_type')
            ->update(['work_type' => 'Harian']);

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employee_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropColumn('work_type');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_type')->default('Harian');
        });
    }
};
