<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class OrderItem extends Model
{
    public const STATUSES_REQUIRING_PHOTO = ['Produksi', 'Distribusi'];

    protected $fillable = [
        'order_id',
        'product',
        'product_id',
        'sku',
        'dimensions',
        'category_id',
        'subcontractor_id',
        'qty',
        'qty_completed',
        'factory_status',
        'price',
        'color',
        'source',
        'status',
        'image',
        'status_image',
        'status_images',
        'factory_drawing_image',
        'factory_sale_price',
        'factory_price_set_at',
        'factory_handover_at',
        'pb_accepted_at',
        'stock_business_unit',
        'stock_bali_quantity',
        'stock_factory_quantity',
        'stock_received_quantity',
        'stock_added_quantity',
        'packed_quantity',
        'quality_good_quantity',
        'quality_damaged_quantity',
        'quality_short_quantity',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'factory_sale_price' => 'decimal:2',
        'factory_price_set_at' => 'datetime',
        'factory_handover_at' => 'datetime',
        'pb_accepted_at' => 'datetime',
        'qty_progress_updated_at' => 'datetime',
        'status_images' => 'array',
        'stock_bali_quantity' => 'integer',
        'stock_factory_quantity' => 'integer',
        'stock_received_quantity' => 'integer',
        'stock_added_quantity' => 'integer',
    ];

    public function getStatusEvidenceImagesAttribute(): array
    {
        return array_values(array_unique(array_filter([
            ...($this->status_images ?? []),
            $this->status_image,
        ])));
    }

    protected static function booted(): void
    {
        static::creating(function (OrderItem $orderItem): void {
            $orderItem->qty_progress_updated_at ??= now();
            $orderItem->factory_status ??= $orderItem->status ?? 'None';
            if ($orderItem->factory_sale_price !== null) {
                $orderItem->factory_price_set_at ??= now();
            }
        });

        static::created(function (OrderItem $orderItem): void {
            $orderItem->touchOrderActivity();
            $orderItem->startFactoryWorkIfNeeded();
        });

        static::updating(function (OrderItem $orderItem): void {
            if ($orderItem->isDirty('qty_completed')) {
                $orderItem->qty_progress_updated_at = now();
            }

            if ($orderItem->isDirty('factory_sale_price') && $orderItem->factory_sale_price !== null) {
                $orderItem->factory_price_set_at = now();
            }
        });

        static::updated(function (OrderItem $orderItem): void {
            $orderItem->touchOrderActivity();
            $orderItem->startFactoryWorkIfNeeded();

            if ($orderItem->wasChanged('status') && $orderItem->status === 'Produksi') {
                $orderItem->order()->first()?->synchronizeProductionStatusFromItems();
            }
        });

        static::deleted(function (OrderItem $orderItem): void {
            $orderItem->touchOrderActivity();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    private function touchOrderActivity(): void
    {
        Order::query()
            ->whereKey($this->order_id)
            ->update([
                'last_edit' => now(),
                'updated_at' => now(),
            ]);
    }

    private function startFactoryWorkIfNeeded(): void
    {
        if (in_array('Produksi', [$this->status, $this->factory_status], true)) {
            $this->order()->first()?->startFactoryWork();
        }
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function addInternalStockOutput(User $user): int
    {
        $order = $this->order()->firstOrFail();
        if (! $order->is_stock_order || ! in_array($this->factory_status, ['Produksi', 'Finishing'], true)) {
            return 0;
        }

        if ($this->product_id === null) {
            throw ValidationException::withMessages([
                "items.{$this->id}.status" => 'Produk katalog wajib dipilih sebelum hasil produksi stok internal dapat masuk stok.',
            ]);
        }

        $product = Product::query()->lockForUpdate()->findOrFail($this->product_id);
        if ($product->factory_sale_price === null) {
            throw ValidationException::withMessages([
                "items.{$this->id}.status" => 'Harga PF katalog wajib diisi sebelum produk masuk stok.',
            ]);
        }

        $completedQuantity = min((int) $this->qty, $this->overall_qty_completed);
        $quantityToAdd = max(0, $completedQuantity - (int) $this->stock_added_quantity);
        if ($quantityToAdd === 0) {
            return 0;
        }

        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('business_unit', User::UNIT_FACTORY)
            ->lockForUpdate()
            ->first();

        if ($stock === null) {
            ProductStock::create([
                'product_id' => $product->id,
                'business_unit' => User::UNIT_FACTORY,
                'quantity' => $quantityToAdd,
                'updated_by_id' => $user->id,
            ]);
        } else {
            $stock->increment('quantity', $quantityToAdd, ['updated_by_id' => $user->id]);
        }

        $this->update([
            'stock_added_quantity' => (int) $this->stock_added_quantity + $quantityToAdd,
        ]);
        $order->events()->create([
            'order_item_id' => $this->id,
            'type' => 'internal_stock_added',
            'title' => 'Produk stok internal masuk stok PF',
            'description' => "{$this->product}: {$quantityToAdd} unit ditambahkan ke stok Pilar Factory.",
            'occurred_at' => now(),
        ]);

        return $quantityToAdd;
    }

    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    public function subcontractorAssignments(): HasMany
    {
        return $this->hasMany(SubcontractorAssignment::class);
    }

    public function pieceworkItems(): HasMany
    {
        return $this->hasMany(PieceworkItem::class);
    }

    public function pieceworkAssignments(): HasMany
    {
        return $this->hasMany(PieceworkAssignment::class);
    }

    public function finishingAssignments(): HasMany
    {
        return $this->hasMany(FinishingAssignment::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(OrderItemService::class);
    }

    public function qualityEvents(): HasMany
    {
        return $this->hasMany(OrderEvent::class)
            ->whereIn('type', ['item_quality_checked', 'item_service_result_recorded'])
            ->whereNotNull('image_path')
            ->latest('occurred_at');
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(OrderItemCancellation::class);
    }

    public function activeSubcontractorAssignments(): HasMany
    {
        return $this->subcontractorAssignments()->whereNull('cancelled_at');
    }

    public function activeSubcontractedQuantity(): int
    {
        return (int) $this->activeSubcontractorAssignments()->sum('assigned_quantity');
    }

    public function getFactoryQuantityAttribute(): int
    {
        $isRework = (int) $this->quality_damaged_quantity > 0
            || ($this->source === 'Stock'
                && $this->status === 'Produksi'
                && in_array($this->factory_status, ['Siap Produksi', 'Produksi'], true));
        if ($isRework) {
            $assignments = $this->relationLoaded('activeSubcontractorAssignments')
                ? $this->activeSubcontractorAssignments
                : $this->activeSubcontractorAssignments()->get([
                    'assigned_quantity',
                    'status',
                    'is_rework',
                ]);
            $assignedQuantity = $assignments
                ->where('is_rework', true)
                ->where('status', '!=', 'Distribusi')
                ->sum('assigned_quantity');

            return max(0, (int) $this->quality_damaged_quantity - $assignedQuantity);
        }

        $assignedQuantity = array_key_exists('active_subcontracted_quantity', $this->attributes)
            ? (int) ($this->attributes['active_subcontracted_quantity'] ?? 0)
            : $this->activeSubcontractedQuantity();
        $workQuantity = (int) $this->qty;

        return max(0, $workQuantity - $assignedQuantity);
    }

    public function getFactoryQtyRemainingAttribute(): int
    {
        if (
            (int) $this->quality_damaged_quantity > 0
            || ($this->source === 'Stock'
                && $this->status === 'Produksi'
                && in_array($this->factory_status, ['Siap Produksi', 'Produksi'], true))
        ) {
            return $this->factory_quantity;
        }

        return max(
            0,
            $this->factory_quantity
                - (int) $this->qty_completed
                - ($this->source === 'Stock' ? 0 : (int) $this->quality_short_quantity),
        );
    }

    public function getFactoryProductionQtyRemainingAttribute(): int
    {
        if ($this->source === 'Stock') {
            return $this->factory_qty_remaining;
        }

        $assignedQuantity = array_key_exists('active_subcontracted_quantity', $this->attributes)
            ? (int) ($this->attributes['active_subcontracted_quantity'] ?? 0)
            : $this->activeSubcontractedQuantity();

        return max(
            0,
            (int) $this->qty
                - $assignedQuantity
                - (int) $this->qty_completed
                - (int) $this->quality_short_quantity
                - (int) $this->quality_damaged_quantity,
        );
    }

    public function allowsRegularProductionAssignment(): bool
    {
        if ($this->source === 'Stock') {
            return in_array($this->factory_status, ['Siap Produksi', 'Produksi'], true)
                && $this->factory_production_qty_remaining > 0;
        }

        return (
            in_array($this->factory_status, [
                'Siap Produksi',
                'Produksi',
                'Finishing',
                'Packing',
                'Proses Kirim',
                'Distribusi',
                'Dalam Perjalanan',
            ], true)
            && $this->factory_production_qty_remaining > 0
        ) || (
            $this->factory_status === 'Service'
            && (int) $this->quality_damaged_quantity > 0
        );
    }

    public function getFinishingQuantityAvailableAttribute(): int
    {
        $assignments = $this->relationLoaded('finishingAssignments')
            ? $this->finishingAssignments
            : $this->finishingAssignments()->get();
        $reservedQuantity = $assignments
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->where('is_rework', false)
            ->sum(fn (FinishingAssignment $assignment): int => $assignment->remaining_quantity);

        return max(
            0,
            (int) $this->qty_completed
                - (int) $this->quality_good_quantity
                - (int) $this->quality_damaged_quantity
                - (int) $this->quality_short_quantity
                - (int) $this->packed_quantity
                - $reservedQuantity,
        );
    }

    public function getReworkQuantityAvailableAttribute(): int
    {
        $assignments = $this->relationLoaded('finishingAssignments')
            ? $this->finishingAssignments
            : $this->finishingAssignments()->get();
        $reservedQuantity = $assignments
            ->where('is_rework', true)
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->sum(fn (FinishingAssignment $assignment): int => $assignment->remaining_quantity);

        return max(0, (int) $this->quality_damaged_quantity - $reservedQuantity);
    }

    public function getQualityUncheckedQuantityAttribute(): int
    {
        $pendingServiceQuantity = $this->source === 'Stock'
            ? $this->getPendingServiceQuantity()
            : 0;

        return max(
            0,
            (int) $this->qty
                - (int) $this->quality_good_quantity
                - (int) $this->quality_damaged_quantity
                - (int) $this->quality_short_quantity
                - $pendingServiceQuantity,
        );
    }

    public function getFinishingCompletedQuantityAttribute(): int
    {
        return min(
            max(0, (int) $this->qty),
            max(
                0,
                (int) $this->packed_quantity
                    + (int) $this->quality_good_quantity
                    + (int) $this->quality_damaged_quantity
                    + (int) $this->quality_short_quantity,
            ),
        );
    }

    public function getQualityInspectedQuantityAttribute(): int
    {
        return min(
            $this->finishing_completed_quantity,
            max(
                0,
                (int) $this->quality_good_quantity
                    + (int) $this->quality_damaged_quantity
                    + (int) $this->quality_short_quantity,
            ),
        );
    }

    public function getQualityInspectionAvailableQuantityAttribute(): int
    {
        return min(
            max(0, (int) $this->packed_quantity),
            max(0, $this->finishing_completed_quantity - $this->quality_inspected_quantity),
        );
    }

    public function getServiceQuantityAttribute(): int
    {
        if ($this->source === 'Stock') {
            return $this->getPendingServiceQuantity();
        }

        return $this->status === 'Service' ? (int) $this->quality_damaged_quantity : 0;
    }

    public function getCompletedQuantityAttribute(): int
    {
        $qualityCompletedQuantity = (int) $this->quality_good_quantity + (int) $this->quality_short_quantity;

        return $this->status === 'Ready'
            ? max((int) $this->qty, $qualityCompletedQuantity)
            : $qualityCompletedQuantity;
    }

    public function getProductionQuantityAttribute(): int
    {
        if ($this->status !== 'Produksi') {
            return 0;
        }

        return $this->source === 'Stock'
            ? (int) $this->quality_damaged_quantity
            : max(0, (int) $this->qty - (int) $this->qty_completed - (int) $this->quality_short_quantity);
    }

    private function getPendingServiceQuantity(): int
    {
        if (array_key_exists('pending_service_quantity', $this->attributes)) {
            return (int) $this->attributes['pending_service_quantity'];
        }

        if ($this->relationLoaded('services')) {
            return (int) $this->services
                ->where('status', 'Service')
                ->sum('quantity');
        }

        return (int) $this->services()
            ->where('status', 'Service')
            ->sum('quantity');
    }

    public function getQualityShortageAvailableAttribute(): int
    {
        $pieceworkAssignments = $this->relationLoaded('pieceworkAssignments')
            ? $this->pieceworkAssignments
            : $this->pieceworkAssignments()
                ->whereNull('completed_at')
                ->whereNull('cancelled_at')
                ->withSum('pieceworkItems as produced_quantity', 'quantity')
                ->get(['id', 'target_quantity', 'completed_quantity', 'cancelled_quantity']);
        $reservedPieceworkQuantity = $pieceworkAssignments
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->sum(fn (PieceworkAssignment $assignment): int => max(
                (int) $assignment->target_quantity
                    - (int) ($assignment->produced_quantity ?? 0)
                    - (int) $assignment->completed_quantity
                    - (int) $assignment->cancelled_quantity,
                0,
            ));
        $subcontractorAssignments = $this->relationLoaded('activeSubcontractorAssignments')
            ? $this->activeSubcontractorAssignments
            : $this->activeSubcontractorAssignments()->get(['assigned_quantity', 'qty_completed']);
        $reservedSubcontractQuantity = $subcontractorAssignments
            ->sum(fn (SubcontractorAssignment $assignment): int => max(
                (int) $assignment->assigned_quantity - (int) $assignment->qty_completed,
                0,
            ));
        $pendingFinishingQuantity = max(
            0,
            (int) $this->qty_completed
                - (int) $this->quality_good_quantity
                - (int) $this->quality_damaged_quantity
                - (int) $this->quality_short_quantity
                - (int) $this->packed_quantity,
        );

        return max(
            0,
            (int) $this->qty
                - (int) $this->quality_good_quantity
                - (int) $this->quality_damaged_quantity
                - (int) $this->quality_short_quantity
                - $pendingFinishingQuantity
                - $reservedPieceworkQuantity
                - $reservedSubcontractQuantity,
        );
    }

    public function synchronizeWorkflowStatuses(): void
    {
        $isFactoryService = $this->factory_status === 'Service'
            && (int) $this->quality_damaged_quantity > 0;
        $isProductionRework = (int) $this->quality_damaged_quantity > 0
            && $this->status === 'Produksi'
            && (in_array($this->factory_status, ['None', 'Siap Produksi', 'Produksi'], true) || $isFactoryService);
        $nextStatus = match (true) {
            $isProductionRework => 'Produksi',
            (int) $this->quality_good_quantity + (int) $this->quality_short_quantity >= (int) $this->qty
                && (int) $this->quality_damaged_quantity === 0
                && (int) $this->packed_quantity === 0 => 'Ready',
            (int) $this->quality_damaged_quantity > 0 => 'Finishing',
            (int) $this->packed_quantity > 0
                || (
                    (int) $this->quality_good_quantity
                        + (int) $this->quality_damaged_quantity
                        + (int) $this->quality_short_quantity > 0
                    && $this->quality_unchecked_quantity > 0
                ) => 'Dalam Perjalanan',
            $this->factory_qty_remaining > 0 => 'Produksi',
            (int) $this->qty_completed > 0 => 'Finishing',
            default => $this->status === 'None' ? 'None' : 'Produksi',
        };
        $nextFactoryStatus = match (true) {
            $isFactoryService => 'Service',
            $isProductionRework => $this->factory_status,
            $this->factory_qty_remaining > 0 => 'Produksi',
            (int) $this->quality_damaged_quantity > 0 => 'Finishing',
            (int) $this->packed_quantity > 0 => 'Packing',
            (int) $this->quality_good_quantity + (int) $this->quality_short_quantity >= (int) $this->qty => 'Ready',
            (int) $this->qty_completed > 0 => 'Finishing',
            default => $this->factory_status,
        };

        if ($nextStatus !== $this->status || $nextFactoryStatus !== $this->factory_status) {
            $this->update([
                'status' => $nextStatus,
                'factory_status' => $nextFactoryStatus,
            ]);
        }
    }

    public function getOverallQtyCompletedAttribute(): int
    {
        $subcontractorCompleted = array_key_exists('active_subcontractor_completed_quantity', $this->attributes)
            ? (int) ($this->attributes['active_subcontractor_completed_quantity'] ?? 0)
            : (int) $this->activeSubcontractorAssignments()->sum('qty_completed');

        return min((int) $this->qty, (int) $this->qty_completed + $subcontractorCompleted);
    }

    public function synchronizeStatusFromWorkAllocations(): void
    {
        if ($this->factory_status === 'Service' && (int) $this->quality_damaged_quantity > 0) {
            return;
        }

        $assignments = $this->activeSubcontractorAssignments()->get([
            'assigned_quantity',
            'status',
        ]);
        $factoryQuantity = $this->factory_qty_remaining;
        $statuses = $assignments->pluck('status');

        if ($factoryQuantity > 0) {
            $statuses->push($this->factory_status ?: 'None');
        }

        if ($statuses->isEmpty()) {
            $statuses->push($this->factory_status ?: 'None');
        }

        $nextStatus = match (true) {
            $statuses->every(fn (string $status): bool => in_array($status, ['Distribusi', 'Ready'], true)) => $statuses->contains('Ready') || $this->status === 'Ready' ? 'Ready' : 'Distribusi',
            $statuses->contains('Produksi') || $statuses->contains('Siap Produksi') => 'Produksi',
            $statuses->contains('Proses Kirim') => 'Proses Kirim',
            default => 'None',
        };
        $nextFactoryStatus = match (true) {
            $statuses->contains('Produksi') => 'Produksi',
            $statuses->contains('Proses Kirim') => 'Proses Kirim',
            $statuses->every(fn (string $status): bool => in_array($status, ['Distribusi', 'Ready'], true)) => $nextStatus,
            default => $this->factory_status,
        };

        if ($nextStatus !== $this->status || $nextFactoryStatus !== $this->factory_status) {
            $this->update([
                'status' => $nextStatus,
                'factory_status' => $nextFactoryStatus,
            ]);
        }
    }

    public function getSubtotalAttribute(): float
    {
        return $this->qty * $this->price;
    }

    public function getQtyRemainingAttribute(): int
    {
        return max(0, (int) $this->qty - (int) $this->qty_completed);
    }

    public function getDaysRemainingAttribute(): ?int
    {
        $deadline = $this->order?->deadline;

        return $deadline ? (int) today()->diffInDays($deadline, false) : null;
    }
}
