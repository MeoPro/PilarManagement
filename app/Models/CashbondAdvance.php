<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashbondAdvance extends Model
{
    protected $fillable = [
        'employee_id',
        'recorded_by_id',
        'advance_date',
        'salary_earned',
        'amount',
        'balance_after',
    ];

    protected function casts(): array
    {
        return [
            'advance_date' => 'date',
            'salary_earned' => 'decimal:2',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
