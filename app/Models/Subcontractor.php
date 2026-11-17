<?php

namespace App\Models;

use Database\Factories\SubcontractorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subcontractor extends Model
{
    /** @use HasFactory<SubcontractorFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SubcontractorAssignment::class);
    }

    public function getSuccessPercentageAttribute(): float
    {
        $assignedQuantity = (int) ($this->assigned_quantity ?? 0);

        if ($assignedQuantity === 0) {
            return 0;
        }

        $completedQuantity = min(
            $assignedQuantity,
            max(0, (int) ($this->completed_quantity ?? 0)),
        );

        return round($completedQuantity / $assignedQuantity * 100, 1);
    }
}
