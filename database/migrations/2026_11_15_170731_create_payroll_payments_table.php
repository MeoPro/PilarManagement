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
        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('daily_pay_total', 15, 2)->default(0);
            $table->decimal('piecework_pay_total', 15, 2)->default(0);
            $table->decimal('salary_paid', 15, 2)->default(0);
            $table->decimal('cashbond_paid', 15, 2)->default(0);
            $table->decimal('cashbond_balance_after', 15, 2)->default(0);
            $table->foreignId('paid_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at');
            $table->timestamps();

            $table->index(['employee_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_payments');
    }
};
