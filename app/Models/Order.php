<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_id',
        'guest_id',
        'guest_name',
        'guest_source',
        'order_type',
        'phone',
        'total_order',
        'paid_amount',
        'deadline',
        'last_edit',
        'status',
        'note',
    ];

    protected $casts = [
        'deadline' => 'date',
        'last_edit' => 'datetime',
        'total_order' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(OrderComment::class)->oldest();
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function canBeDeleted(): bool
    {
        if ($this->status !== 'None') {
            return false;
        }

        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->with('activeSubcontractorAssignments')->get();

        return $items->every(function (OrderItem $item): bool {
            $hasActiveSubcontractorAssignments = array_key_exists('active_subcontracted_quantity', $item->getAttributes())
                ? (int) $item->active_subcontracted_quantity > 0
                : ($item->relationLoaded('activeSubcontractorAssignments')
                    ? $item->activeSubcontractorAssignments->isNotEmpty()
                    : $item->activeSubcontractorAssignments()->exists());

            return $item->status === 'None'
                && $item->factory_status === 'None'
                && (int) $item->qty_completed === 0
                && ! $hasActiveSubcontractorAssignments;
        });
    }

    public function getIsUrgentAttribute(): bool
    {
        if (in_array($this->status, ['Selesai', 'Cancel'])) {
            return false;
        }

        if ($this->deadline) {
            $daysRemaining = (int) today()->diffInDays($this->deadline, false);

            if ($daysRemaining <= 14) {
                return true;
            }
        }

        if (! $this->last_edit) {
            return false;
        }

        return $this->last_edit->diffInDays(now()) >= 7;
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->is_urgent) {
            return 'Urgent';
        }

        return $this->status;
    }

    public function getAmountRemainingAttribute(): float
    {
        return max(0, round((float) $this->total_order - (float) $this->paid_amount, 2));
    }

    public function getPaidPercentageAttribute(): float
    {
        if ((float) $this->total_order <= 0) {
            return 0;
        }

        return round(min((float) $this->paid_amount, (float) $this->total_order) / (float) $this->total_order * 100, 1);
    }

    public function getReadyPercentageAttribute(): float
    {
        $totalQuantity = (int) ($this->attributes['items_sum_qty'] ?? 0);
        $readyQuantity = (int) ($this->attributes['ready_units'] ?? 0);

        return $totalQuantity > 0
            ? round($readyQuantity / $totalQuantity * 100, 1)
            : 0.0;
    }

    public function getIsPaidAttribute(): bool
    {
        return (float) $this->total_order > 0
            && (float) $this->paid_amount >= (float) $this->total_order;
    }

    public function getIsDebtAttribute(): bool
    {
        return $this->status === 'Selesai' && $this->amount_remaining > 0;
    }

    public function synchronizeProductionStatusFromItems(?EloquentCollection $items = null): void
    {
        if (in_array($this->status, ['Selesai', 'Cancel'], true)) {
            return;
        }

        $productionItems = $items?->reject(fn (OrderItem $item): bool => $item->source === 'Stock');

        if ($items !== null && (
            $this->status !== 'None'
            || $items->isEmpty()
            || $productionItems->isEmpty()
            || $productionItems->contains(fn (OrderItem $item): bool => $item->status !== 'Produksi')
        )) {
            return;
        }

        $itemStatuses = $items === null
            ? $this->items()
                ->selectRaw(
                    'COUNT(*) AS total_items,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS ready_items,
                    SUM(CASE WHEN source != ? AND status = ? THEN 1 ELSE 0 END) AS production_items,
                    SUM(CASE WHEN source != ? THEN 1 ELSE 0 END) AS production_total_items,
                    SUM(CASE WHEN source != ? AND status IN (?, ?) THEN 1 ELSE 0 END) AS finishing_items,
                    SUM(CASE WHEN source != ? AND status = ? THEN 1 ELSE 0 END) AS distributed_items',
                    ['Ready', 'Stock', 'Produksi', 'Stock', 'Stock', 'Finishing', 'Dalam Perjalanan', 'Stock', 'Distribusi'],
                )
                ->first()
            : (object) [
                'total_items' => $items->count(),
                'ready_items' => $items->where('status', 'Ready')->count(),
                'production_items' => $productionItems->where('status', 'Produksi')->count(),
                'production_total_items' => $productionItems->count(),
                'finishing_items' => $productionItems->whereIn('status', ['Finishing', 'Dalam Perjalanan'])->count(),
                'distributed_items' => $productionItems->where('status', 'Distribusi')->count(),
            ];

        if (! $itemStatuses || (int) $itemStatuses->total_items === 0) {
            return;
        }

        $nextStatus = match (true) {
            (int) $itemStatuses->ready_items === (int) $itemStatuses->total_items => 'Selesai',
            (int) $itemStatuses->production_total_items > 0
                && (int) $itemStatuses->production_items === (int) $itemStatuses->production_total_items => 'Proses Produksi',
            (int) $itemStatuses->production_total_items > 0
                && (int) $itemStatuses->distributed_items === (int) $itemStatuses->production_total_items => 'Finishing',
            (int) $itemStatuses->finishing_items > 0 => 'Finishing',
            default => null,
        };

        if ($nextStatus === null || $nextStatus === $this->status) {
            return;
        }

        $previousStatus = $this->status;
        $this->update([
            'status' => $nextStatus,
            'last_edit' => now(),
        ]);
        $this->events()->create([
            'type' => 'order_status_changed',
            'title' => 'Status order berubah otomatis',
            'description' => "{$this->order_id}: {$previousStatus} -> {$nextStatus} (berdasarkan status seluruh produk)",
            'occurred_at' => now(),
        ]);
    }
}
