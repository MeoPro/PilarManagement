<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        'pending_deadline',
        'pending_deadline_reason',
        'pending_deadline_requested_by',
        'pending_deadline_requested_at',
        'factory_deadline',
        'factory_started_at',
        'last_edit',
        'status',
        'note',
    ];

    protected $casts = [
        'deadline' => 'date',
        'pending_deadline' => 'date',
        'pending_deadline_requested_at' => 'datetime',
        'factory_deadline' => 'date',
        'factory_started_at' => 'datetime',
        'last_edit' => 'datetime',
        'total_order' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->isDirty('deadline') && $order->factory_started_at !== null) {
                $order->factory_deadline = $order->calculateFactoryDeadline();
            }

            $order->last_edit = now();
        });
    }

    /**
     * Mark the order's first production handoff and freeze its internal deadline.
     */
    public function startFactoryWork(): void
    {
        if ($this->factory_started_at !== null) {
            return;
        }

        $this->factory_started_at = now();
        $this->factory_deadline = $this->calculateFactoryDeadline();
        $this->save();
    }

    public function getIsUrgentAttribute(): bool
    {
        return $this->isUrgentForDeadline($this->deadline);
    }

    public function getIsFactoryUrgentAttribute(): bool
    {
        return $this->isUrgentForDeadline($this->factory_deadline ?? $this->deadline);
    }

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

    public function pendingDeadlineRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pending_deadline_requested_by');
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

    private function isUrgentForDeadline(?Carbon $deadline): bool
    {
        if (in_array($this->status, ['Selesai', 'Cancel'])) {
            return false;
        }

        if ($deadline !== null) {
            $daysRemaining = (int) today()->diffInDays($deadline, false);

            if ($daysRemaining <= 14) {
                return true;
            }
        }

        $lastActivityAt = $this->last_edit ?? $this->updated_at ?? $this->created_at;

        return $lastActivityAt !== null && $lastActivityAt->lessThanOrEqualTo(now()->subDays(7));
    }

    private function calculateFactoryDeadline(): ?string
    {
        if ($this->factory_started_at === null || $this->deadline === null) {
            return null;
        }

        $factoryStart = $this->factory_started_at->copy()->startOfDay();
        $deliveryDeadline = $this->deadline->copy()->startOfDay();
        $twoMonthFactoryWindow = $factoryStart->copy()->addMonthsNoOverflow(2);
        $factoryDeadline = $deliveryDeadline->greaterThanOrEqualTo($twoMonthFactoryWindow)
            ? $deliveryDeadline->copy()->subMonthNoOverflow()
            : $deliveryDeadline->copy()->subWeek();

        if ($factoryDeadline->lessThan($factoryStart)) {
            $factoryDeadline = $factoryStart;
        }

        return $factoryDeadline->toDateString();
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
        if ($this->status === 'Cancel') {
            return;
        }

        $itemStatuses = $items === null
            ? $this->items()
                ->selectRaw(
                    'COUNT(*) AS total_items,
                    SUM(CASE WHEN source != ? AND status = ? THEN 1 ELSE 0 END) AS new_order_items,
                    SUM(CASE WHEN EXISTS (SELECT 1 FROM order_item_services AS services WHERE services.order_item_id = order_items.id AND services.status = ? AND services.quantity > 0) THEN 1 ELSE 0 END) AS service_items,
                    SUM(CASE WHEN status IN (?, ?) OR factory_status IN (?, ?) THEN 1 ELSE 0 END) AS production_items,
                    SUM(CASE WHEN source != ? AND (status = ? OR factory_status = ?) THEN 1 ELSE 0 END) AS finishing_items,
                    SUM(CASE WHEN source != ? AND (status = ? OR factory_status = ?) THEN 1 ELSE 0 END) AS packing_items,
                    SUM(CASE WHEN source != ? AND (status = ? OR factory_status = ?) THEN 1 ELSE 0 END) AS distributed_order_items,
                    SUM(CASE WHEN source = ? THEN 1 ELSE 0 END) AS stock_items,
                    SUM(CASE WHEN source != ? THEN 1 ELSE 0 END) AS order_items,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS ready_items',
                    [
                        'Stock',
                        'None',
                        'Service',
                        'Produksi',
                        'Produksi',
                        'Produksi',
                        'Produksi',
                        'Stock',
                        'Finishing',
                        'Finishing',
                        'Stock',
                        'Packing',
                        'Packing',
                        'Stock',
                        'Distribusi',
                        'Distribusi',
                        'Stock',
                        'Stock',
                        'Ready',
                    ],
                )
                ->first()
            : (object) [
                'total_items' => $items->count(),
                'new_order_items' => $items
                    ->filter(fn (OrderItem $item): bool => $item->source !== 'Stock' && $item->status === 'None')
                    ->count(),
                'service_items' => OrderItemService::query()
                    ->whereIn('order_item_id', $items->modelKeys())
                    ->where('status', 'Service')
                    ->where('quantity', '>', 0)
                    ->distinct()
                    ->count('order_item_id'),
                'production_items' => $items
                    ->filter(fn (OrderItem $item): bool => in_array('Produksi', [$item->status, $item->factory_status], true))
                    ->count(),
                'finishing_items' => $items
                    ->filter(fn (OrderItem $item): bool => $item->source !== 'Stock'
                        && in_array('Finishing', [$item->status, $item->factory_status], true))
                    ->count(),
                'packing_items' => $items
                    ->filter(fn (OrderItem $item): bool => $item->source !== 'Stock'
                        && in_array('Packing', [$item->status, $item->factory_status], true))
                    ->count(),
                'distributed_order_items' => $items
                    ->filter(fn (OrderItem $item): bool => $item->source !== 'Stock'
                        && in_array('Distribusi', [$item->status, $item->factory_status], true))
                    ->count(),
                'stock_items' => $items->where('source', 'Stock')->count(),
                'order_items' => $items->where('source', '!=', 'Stock')->count(),
                'ready_items' => $items->where('status', 'Ready')->count(),
            ];

        if (! $itemStatuses || (int) $itemStatuses->total_items === 0) {
            return;
        }

        $hasMixedStockItems = (int) $itemStatuses->stock_items > 0
            && (int) $itemStatuses->order_items > 0;

        $nextStatus = match (true) {
            (int) $itemStatuses->new_order_items > 0 => 'None',
            (int) $itemStatuses->production_items > 0 => 'Proses Produksi',
            (int) $itemStatuses->finishing_items > 0 => 'Finishing',
            $hasMixedStockItems && (int) $itemStatuses->service_items === 0 => 'Finishing',
            (int) $itemStatuses->packing_items > 0 => 'Packing',
            (int) $itemStatuses->service_items > 0 => 'Service',
            (int) $itemStatuses->distributed_order_items > 0 => 'Distribusi',
            (int) $itemStatuses->stock_items > 0 => 'Distribusi',
            (int) $itemStatuses->ready_items === (int) $itemStatuses->total_items => 'Selesai',
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
            'description' => "{$this->order_id}: {$previousStatus} -> {$nextStatus} (berdasarkan tahapan produk)",
            'occurred_at' => now(),
        ]);
    }
}
