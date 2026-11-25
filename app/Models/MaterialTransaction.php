<?php

namespace App\Models;

use Database\Factories\MaterialTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialTransaction extends Model
{
    /** @use HasFactory<MaterialTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'material_id',
        'created_by_id',
        'type',
        'quantity',
        'unit_price',
        'note',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getQuantityDisplayAttribute(): string
    {
        return rtrim(rtrim(number_format((float) $this->quantity, 3, ',', '.'), '0'), ',');
    }
}
