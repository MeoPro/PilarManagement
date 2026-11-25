<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $fillable = [
        'business_unit',
        'name',
        'unit',
        'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MaterialTransaction::class);
    }

    public function getCurrentStockAttribute(): float
    {
        return round(
            (float) ($this->stock_in_quantity ?? 0) - (float) ($this->stock_out_quantity ?? 0),
            3,
        );
    }

    public function getCurrentStockDisplayAttribute(): string
    {
        return rtrim(rtrim(number_format($this->current_stock, 3, ',', '.'), '0'), ',');
    }

    public function getAverageUnitPriceAttribute(): ?float
    {
        if ($this->current_stock <= 0) {
            return null;
        }

        return round((float) ($this->current_inventory_value ?? 0) / $this->current_stock, 2);
    }
}
