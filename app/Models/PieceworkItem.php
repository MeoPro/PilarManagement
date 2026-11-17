<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceworkItem extends Model
{
    protected $fillable = [
        'employee_id',
        'order_item_id',
        'piecework_assignment_id',
        'product_name',
        'quantity',
        'unit_price',
        'line_total',
        'target_deadline',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'target_deadline' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PieceworkItem $pieceworkItem): void {
            if ($pieceworkItem->employee_id !== null || $pieceworkItem->employee_attendance_id === null) {
                return;
            }

            $pieceworkItem->employee_id = EmployeeAttendance::query()
                ->whereKey($pieceworkItem->employee_attendance_id)
                ->value('employee_id');
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendance::class, 'employee_attendance_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(PieceworkAssignment::class, 'piecework_assignment_id');
    }
}
