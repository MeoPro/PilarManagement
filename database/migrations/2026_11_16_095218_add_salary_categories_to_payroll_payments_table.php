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
        Schema::table('payroll_payments', function (Blueprint $table) {
            $table->decimal('daily_salary_paid', 15, 2)->default(0);
            $table->decimal('piecework_salary_paid', 15, 2)->default(0);
        });

        DB::table('payroll_payments')
            ->select(['id', 'daily_pay_total', 'piecework_pay_total', 'salary_paid'])
            ->orderBy('id')
            ->chunkById(200, function ($payments): void {
                foreach ($payments as $payment) {
                    $dailySalaryPaid = min((float) $payment->salary_paid, (float) $payment->daily_pay_total);
                    $pieceworkSalaryPaid = min(
                        max((float) $payment->salary_paid - $dailySalaryPaid, 0),
                        (float) $payment->piecework_pay_total,
                    );

                    DB::table('payroll_payments')
                        ->where('id', $payment->id)
                        ->update([
                            'daily_salary_paid' => $dailySalaryPaid,
                            'piecework_salary_paid' => $pieceworkSalaryPaid,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_payments', function (Blueprint $table) {
            $table->dropColumn(['daily_salary_paid', 'piecework_salary_paid']);
        });
    }
};
