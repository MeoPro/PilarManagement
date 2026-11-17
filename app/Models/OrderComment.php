<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderComment extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'parent_id',
        'business_unit',
        'body',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(OrderCommentRecipient::class);
    }

    public function readRecipients(): HasMany
    {
        return $this->recipients()->whereNotNull('read_at');
    }
}
