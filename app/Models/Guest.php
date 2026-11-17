<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    private const CLOSED_ORDER_STATUSES = ['Selesai', 'Cancel'];

    protected $fillable = [
        'name',
        'guest_source',
        'address',
    ];

    public function phones(): HasMany
    {
        return $this->hasMany(GuestPhone::class)->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function activeOrders(): HasMany
    {
        return $this->orders()->where(function (Builder $query): void {
            $query->whereNull('status')
                ->orWhereNotIn('status', self::CLOSED_ORDER_STATUSES);
        });
    }

    public function hasActiveOrders(): bool
    {
        if ($this->relationLoaded('orders')) {
            return $this->orders->contains(
                fn (Order $order): bool => ! in_array($order->status, self::CLOSED_ORDER_STATUSES, true),
            );
        }

        return $this->activeOrders()->exists();
    }
}
