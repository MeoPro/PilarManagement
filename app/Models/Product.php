<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public const DIMENSIONS_PATTERN = '/\A\d+(?:[.,]\d+)?x\d+(?:[.,]\d+)?x\d+(?:[.,]\d+)?\z/';

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'category_id',
        'price',
        'dimensions',
        'colors',
        'image',
        'mockup',
        'mockups',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'price' => 'decimal:2',
            'colors' => 'array',
            'mockups' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public function mockupPaths(): array
    {
        return array_values(array_unique(array_filter([
            ...($this->mockups ?? []),
            $this->mockup,
        ], 'is_string')));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public static function normalizeDimensions(string $dimensions): string
    {
        $normalizedDimensions = preg_replace('/\s*[x×]\s*/iu', 'x', trim($dimensions)) ?? trim($dimensions);

        if (! str_contains($normalizedDimensions, 'x')) {
            $parts = preg_split('/\s+/', $normalizedDimensions, -1, PREG_SPLIT_NO_EMPTY);

            if (count($parts) === 3) {
                return implode('x', $parts);
            }
        }

        return $normalizedDimensions;
    }
}
