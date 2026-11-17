<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPayment>
 */
class PayrollPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'period' => now()->format('Y-m'),
            'daily_pay_total' => 0,
            'piecework_pay_total' => 0,
            'salary_paid' => 0,
            'cashbond_paid' => 0,
            'cashbond_balance_after' => 0,
            'paid_by_id' => User::factory()->admin(),
            'paid_at' => now(),
        ];
    }
}
