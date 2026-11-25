<?php

namespace App\Models;

use Database\Factories\PieceworkAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PieceworkAssignment extends Model
{
    /** @use HasFactory<PieceworkAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'order_item_id',
        'assigned_by_id',
        'completed_by_id',
        'cancelled_by_id',
        'product_name',
        'target_quantity',
        'completed_quantity',
        'completion_note',
        'cancelled_quantity',
        'cancellation_note',
        'unit_price',
        'target_deadline',
        'is_rework',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'integer',
            'completed_quantity' => 'integer',
            'cancelled_quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'target_deadline' => 'date',
            'is_rework' => 'boolean',
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

    public function pieceworkItems(): HasMany
    {
        return $this->hasMany(PieceworkItem::class);
    }

    public function getTargetTotalAttribute(): float
    {
        $remainingTarget = max((int) $this->target_quantity - (int) $this->cancelled_quantity, 0);

        return $remainingTarget * (float) ($this->unit_price ?? 0);
    }
}
