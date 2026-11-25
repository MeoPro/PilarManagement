<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemCancellation extends Model
{
    protected $fillable = [
        'order_item_id',
        'quantity',
        'decision',
        'requested_by_id',
        'decided_by_id',
        'stock_factory_quantity',
        'stock_business_unit',
        'decision_note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_factory_quantity' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
