<?php

namespace App\Models;

use Database\Factories\SubcontractorAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubcontractorAssignment extends Model
{
    /** @use HasFactory<SubcontractorAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'subcontractor_id',
        'created_by_id',
        'assigned_quantity',
        'target_deadline',
        'qty_completed',
        'status',
        'is_rework',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_quantity' => 'integer',
            'target_deadline' => 'date',
            'qty_completed' => 'integer',
            'is_rework' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
