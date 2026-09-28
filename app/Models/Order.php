<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_id',
        'guest_name',
        'phone',
        'total_order',
        'deadline',
        'last_edit',
        'status',
        'note',
    ];

    protected $casts = [
        'deadline' => 'date',
        'last_edit' => 'datetime',
        'total_order' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getIsUrgentAttribute(): bool
    {
        if (!$this->last_edit) {
            return false;
        }

        return $this->last_edit->diffInDays(now()) >= 7
            && !in_array($this->status, ['Selesai', 'Cancel']);
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->is_urgent) {
            return 'Urgent';
        }

        return $this->status;
    }
}