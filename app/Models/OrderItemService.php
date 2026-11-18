<?php

namespace App\Models;

use Database\Factories\OrderItemServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemService extends Model
{
    /** @use HasFactory<OrderItemServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'quantity',
        'status',
        'severity',
        'note',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
