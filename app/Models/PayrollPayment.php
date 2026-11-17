<?php

namespace App\Models;

use Database\Factories\PayrollPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPayment extends Model
{
    /** @use HasFactory<PayrollPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'period',
        'daily_pay_total',
        'overtime_pay_total',
        'piecework_pay_total',
        'daily_salary_paid',
        'piecework_salary_paid',
        'salary_paid',
        'cashbond_paid',
        'cashbond_balance_after',
        'paid_by_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'daily_pay_total' => 'decimal:2',
            'overtime_pay_total' => 'decimal:2',
            'piecework_pay_total' => 'decimal:2',
            'daily_salary_paid' => 'decimal:2',
            'piecework_salary_paid' => 'decimal:2',
            'salary_paid' => 'decimal:2',
            'cashbond_paid' => 'decimal:2',
            'cashbond_balance_after' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_id');
    }
}
