<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
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
}
