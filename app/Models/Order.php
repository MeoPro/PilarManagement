<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_id',
        'guest_name',
        'phone',
        'items',
        'color',
        'image_date',
        'image',
        'deadline',
        'urgent',
        'status',
        'note',
    ];

    protected $casts = [
        'items' => 'array',
        'image_date' => 'date',
        'deadline' => 'date',
        'urgent' => 'boolean',
    ];
}