<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeOvertime extends Model
{
    protected $fillable = [
        'employee_id',
        'overtime_date',
        'overtime_hours',
        'hourly_rate',
        'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'overtime_date' => 'date',
            'overtime_hours' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
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
