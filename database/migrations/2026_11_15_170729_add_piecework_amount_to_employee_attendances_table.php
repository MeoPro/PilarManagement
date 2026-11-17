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
            $table->decimal('daily_pay_amount', 15, 2)->default(0);
            $table->decimal('piecework_amount', 15, 2)->default(0);
        });

        DB::table('employee_attendances')
            ->where('work_type', 'Harian')
            ->update([
                'daily_pay_amount' => DB::raw(
                    'COALESCE((SELECT daily_wage / 2 FROM employees WHERE employees.id = employee_attendances.employee_id), 0)',
                ),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropColumn(['daily_pay_amount', 'piecework_amount']);
        });
    }
};
