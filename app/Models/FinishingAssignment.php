<?php

namespace App\Models;

use Database\Factories\FinishingAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishingAssignment extends Model
{
    /** @use HasFactory<FinishingAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'employee_id',
        'assigned_by_id',
        'completed_by_id',
        'cancelled_by_id',
        'target_quantity',
        'completed_quantity',
        'completion_note',
        'cancelled_quantity',
        'cancellation_note',
        'is_rework',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'integer',
            'completed_quantity' => 'integer',
            'cancelled_quantity' => 'integer',
            'is_rework' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function getRemainingQuantityAttribute(): int
    {
        return max(
            (int) $this->target_quantity
                - (int) $this->completed_quantity
                - (int) $this->cancelled_quantity,
            0,
        );
    }
}
