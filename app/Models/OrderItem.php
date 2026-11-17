<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'packed_quantity',
        'quality_good_quantity',
        'quality_damaged_quantity',
        'quality_short_quantity',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'qty_progress_updated_at' => 'datetime',
        'status_images' => 'array',
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
        });

        static::updating(function (OrderItem $orderItem): void {
            if ($orderItem->isDirty('qty_completed')) {
                $orderItem->qty_progress_updated_at = now();
            }
        });

        static::updated(function (OrderItem $orderItem): void {
            if ($orderItem->wasChanged('status') && $orderItem->status === 'Produksi') {
                $orderItem->order()->first()?->synchronizeProductionStatusFromItems();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
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
        $assignedQuantity = array_key_exists('active_subcontracted_quantity', $this->attributes)
            ? (int) ($this->attributes['active_subcontracted_quantity'] ?? 0)
            : $this->activeSubcontractedQuantity();

        return max(0, (int) $this->qty - $assignedQuantity);
    }

    public function getFactoryQtyRemainingAttribute(): int
    {
        return max(
            0,
            $this->factory_quantity
                - (int) $this->qty_completed
                - (int) $this->quality_short_quantity,
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
        return max(
            0,
            (int) $this->qty
                - (int) $this->quality_good_quantity
                - (int) $this->quality_damaged_quantity
                - (int) $this->quality_short_quantity,
        );
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
        $nextStatus = match (true) {
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
        $assignments = $this->activeSubcontractorAssignments()->get([
            'assigned_quantity',
            'status',
        ]);
        $factoryQuantity = max(
            0,
            (int) $this->qty - (int) $assignments->sum('assigned_quantity'),
        );
        $statuses = $assignments->pluck('status');

        if ($factoryQuantity > 0) {
            $statuses->push($this->factory_status ?: 'None');
        }

        if ($statuses->isEmpty()) {
            $statuses->push($this->factory_status ?: 'None');
        }

        $nextStatus = match (true) {
            $statuses->every(fn (string $status): bool => in_array($status, ['Distribusi', 'Ready'], true)) => $statuses->contains('Ready') || $this->status === 'Ready' ? 'Ready' : 'Distribusi',
            $statuses->contains('Produksi') => 'Produksi',
            $statuses->contains('Proses Kirim') => 'Proses Kirim',
            default => 'None',
        };

        if ($nextStatus !== $this->status) {
            $this->update(['status' => $nextStatus]);
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
