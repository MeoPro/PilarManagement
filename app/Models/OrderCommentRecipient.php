<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCommentRecipient extends Model
{
    protected $fillable = [
        'order_comment_id',
        'user_id',
        'read_at',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(OrderComment::class, 'order_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
