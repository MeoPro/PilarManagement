<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends Model
{
    protected $fillable = [
        'order_id',
        'order_item_id',
        'type',
        'title',
        'description',
        'image_path',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (OrderEvent $event): void {
            Order::query()
                ->whereKey($event->order_id)
                ->update([
                    'last_edit' => $event->occurred_at ?? now(),
                    'updated_at' => now(),
                ]);
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
