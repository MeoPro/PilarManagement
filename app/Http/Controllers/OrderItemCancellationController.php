<?php

namespace App\Http\Controllers;

use App\Models\FinishingAssignment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemCancellation;
use App\Models\PieceworkAssignment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderItemCancellationController extends Controller
{
    public function storeForOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSalesWorkflow() && $user->canAccessMenu('orders'), 403);

        DB::transaction(function () use ($order, $user): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->is_stock_order || $lockedOrder->status === 'Cancel') {
                throw ValidationException::withMessages([
                    'order' => 'Pembatalan hanya dapat diajukan untuk order pelanggan yang aktif.',
                ]);
            }

            $items = $lockedOrder->items()->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'order' => 'Order tidak memiliki produk yang dapat dibatalkan.',
                ]);
            }

            $pendingQuantities = OrderItemCancellation::query()
                ->whereIn('order_item_id', $items->modelKeys())
                ->where('decision', 'pending')
                ->select('order_item_id')
                ->selectRaw('SUM(quantity) AS pending_quantity')
                ->groupBy('order_item_id')
                ->pluck('pending_quantity', 'order_item_id');
            $requestedQuantity = 0;
            $requestedItems = 0;

            foreach ($items as $item) {
                $quantity = max(0, (int) $item->qty - (int) $pendingQuantities->get($item->id, 0));
                if ($quantity === 0) {
                    continue;
                }

                $item->cancellationRequests()->create([
                    'quantity' => $quantity,
                    'decision' => 'pending',
                    'requested_by_id' => $user->id,
                ]);
                $requestedQuantity += $quantity;
                $requestedItems++;
            }

            if ($requestedQuantity === 0) {
                throw ValidationException::withMessages([
                    'order' => 'Semua jumlah produk sudah memiliki permintaan pembatalan yang menunggu keputusan PF.',
                ]);
            }

            $lockedOrder->update(['last_edit' => now()]);
            $lockedOrder->events()->create([
                'type' => 'order_cancellation_requested',
                'title' => 'Pembatalan seluruh order diajukan ke PF',
                'description' => "{$lockedOrder->order_id}: {$requestedItems} produk, {$requestedQuantity} unit menunggu keputusan PF.",
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('orders.edit', $order)
            ->with('success', 'Permintaan pembatalan seluruh order dikirim ke PF untuk disetujui.');
    }

    public function store(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSalesWorkflow() && $user->canAccessMenu('orders'), 403);
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'stock_business_unit' => ['nullable', Rule::in(['none', User::UNIT_BALI, User::UNIT_FACTORY])],
        ]);

        DB::transaction(function () use ($orderItem, $user, $validated): void {
            $order = Order::query()->lockForUpdate()->findOrFail($orderItem->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($orderItem->id);

            if ($order->is_stock_order || $order->status === 'Cancel') {
                throw ValidationException::withMessages([
                    'quantity' => 'Pembatalan hanya dapat diajukan pada order pelanggan yang aktif.',
                ]);
            }

            $quantity = (int) $validated['quantity'];
            $pendingQuantity = (int) $item->cancellationRequests()
                ->where('decision', 'pending')
                ->sum('quantity');
            $availableQuantity = max(0, (int) $item->qty - $pendingQuantity);
            if ($quantity > $availableQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Jumlah pembatalan melebihi sisa yang dapat diajukan ({$availableQuantity}).",
                ]);
            }

            $isReady = $item->status === 'Ready';
            $stockBusinessUnit = $validated['stock_business_unit'] ?? 'none';
            if ($isReady && ! in_array($stockBusinessUnit, ['none', User::UNIT_BALI, User::UNIT_FACTORY], true)) {
                throw ValidationException::withMessages([
                    'stock_business_unit' => 'Pilih lokasi stok untuk produk siap yang dibatalkan.',
                ]);
            }

            $cancellation = $item->cancellationRequests()->create([
                'quantity' => $quantity,
                'decision' => $isReady
                    ? ($stockBusinessUnit === 'none' ? 'ready_cancelled' : 'ready_stock')
                    : 'pending',
                'requested_by_id' => $user->id,
                'decided_by_id' => $isReady ? $user->id : null,
                'stock_business_unit' => $isReady && $stockBusinessUnit !== 'none'
                    ? $stockBusinessUnit
                    : null,
                'stock_factory_quantity' => $isReady && $stockBusinessUnit === User::UNIT_FACTORY
                    ? $quantity
                    : 0,
                'decided_at' => $isReady ? now() : null,
            ]);

            if ($isReady) {
                if ($stockBusinessUnit !== 'none') {
                    $product = $this->lockCatalogProduct($item);
                    $this->addProductStock($product, $stockBusinessUnit, $quantity, $user);
                }

                $this->reduceOrderQuantity($order, $item, $quantity, $user);
            }

            $order->events()->create([
                'order_item_id' => $item->id,
                'type' => $isReady ? 'ready_item_cancelled' : 'item_cancellation_requested',
                'title' => $isReady ? 'Produk siap dibatalkan' : 'Pembatalan produk diajukan',
                'description' => "{$order->order_id} - {$item->product}: {$quantity} unit"
                    .($isReady && $stockBusinessUnit !== 'none' ? " ditambahkan ke stok {$stockBusinessUnit}" : ''),
                'occurred_at' => now(),
            ]);
        });

        return back()->with(
            'success',
            $orderItem->status === 'Ready'
                ? 'Pembatalan produk siap berhasil dicatat.'
                : 'Permintaan pembatalan dikirim ke Pilar Factory untuk keputusan.',
        );
    }

    public function update(Request $request, OrderItemCancellation $orderItemCancellation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isFactoryWorkflow() && $user->canAccessMenu('order_products'), 403);
        $decision = (string) $request->input('decision');
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'stock', 'rejected'])],
            'stock_factory_quantity' => [
                Rule::requiredIf($decision === 'stock'),
                'nullable',
                'integer',
                'min:0',
            ],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($orderItemCancellation, $user, $validated): void {
            $initialItem = OrderItem::query()->findOrFail($orderItemCancellation->order_item_id);
            $order = Order::query()->lockForUpdate()->findOrFail($initialItem->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($initialItem->id);
            $cancellation = OrderItemCancellation::query()
                ->whereKey($orderItemCancellation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->is_stock_order || $order->status === 'Cancel' || $cancellation->decision !== 'pending') {
                throw ValidationException::withMessages([
                    'decision' => 'Permintaan ini sudah diputuskan atau tidak lagi dapat diubah.',
                ]);
            }

            $decision = $validated['decision'];
            $stockQuantity = $decision === 'stock'
                ? (int) ($validated['stock_factory_quantity'] ?? 0)
                : 0;
            $internalQuantity = 0;

            if ($decision === 'stock') {
                $completedQuantity = min((int) $item->qty, $item->overall_qty_completed);
                $newOrderQuantity = max(0, (int) $item->qty - (int) $cancellation->quantity);
                $minimumStockQuantity = max(0, $completedQuantity - $newOrderQuantity);
                $maximumStockQuantity = min($completedQuantity, (int) $cancellation->quantity);

                if ($stockQuantity < $minimumStockQuantity || $stockQuantity > $maximumStockQuantity) {
                    throw ValidationException::withMessages([
                        'stock_factory_quantity' => "Jumlah produk selesai yang masuk stok harus {$minimumStockQuantity}–{$maximumStockQuantity} unit.",
                    ]);
                }

                $internalQuantity = (int) $cancellation->quantity - $stockQuantity;
                if ($stockQuantity > 0) {
                    $product = $this->lockCatalogProduct($item);
                    $this->addProductStock($product, User::UNIT_FACTORY, $stockQuantity, $user);
                }
            }

            if ($decision !== 'rejected') {
                $this->reduceOrderQuantity($order, $item, (int) $cancellation->quantity, $user);
                if ($internalQuantity > 0) {
                    $this->createInternalStockOrder($item, $internalQuantity);
                }
            }

            $cancellation->update([
                'decision' => $decision,
                'stock_factory_quantity' => $stockQuantity,
                'stock_business_unit' => $stockQuantity > 0 ? User::UNIT_FACTORY : null,
                'decision_note' => $validated['decision_note'] ?? null,
                'decided_by_id' => $user->id,
                'decided_at' => now(),
            ]);

            $order->events()->create([
                'order_item_id' => $item->id,
                'type' => 'item_cancellation_'.$decision,
                'title' => match ($decision) {
                    'approved' => 'Pembatalan produk disetujui PF',
                    'stock' => 'Pembatalan produk dialihkan ke stok PF',
                    default => 'Pembatalan produk ditolak PF',
                },
                'description' => "{$order->order_id} - {$item->product}: {$cancellation->quantity} unit"
                    .($decision === 'stock' ? ", {$stockQuantity} unit masuk stok PF, {$internalQuantity} unit menjadi produksi stok internal" : ''),
                'occurred_at' => now(),
            ]);
        });

        return back()->with('success', 'Keputusan pembatalan produk berhasil disimpan.');
    }

    private function reduceOrderQuantity(Order $order, OrderItem $item, int $quantity, User $user): void
    {
        $this->cancelUnfinishedWork($item, $quantity, $user);
        $remainingQuantity = max(0, (int) $item->qty - $quantity);
        $updates = [
            'qty' => $remainingQuantity,
            'status' => $remainingQuantity === 0 ? 'Cancel' : $item->status,
            'factory_status' => $remainingQuantity === 0 ? 'Cancel' : $item->factory_status,
        ];
        if ($item->source === 'Stock') {
            $factoryStockCancelled = min((int) $item->stock_factory_quantity, $quantity);
            $baliStockCancelled = min(
                (int) $item->stock_bali_quantity,
                max(0, $quantity - $factoryStockCancelled),
            );
            $remainingFactoryStock = max(0, (int) $item->stock_factory_quantity - $factoryStockCancelled);
            $remainingBaliStock = max(0, (int) $item->stock_bali_quantity - $baliStockCancelled);
            $updates['stock_factory_quantity'] = $remainingFactoryStock;
            $updates['stock_bali_quantity'] = $remainingBaliStock;
            $updates['stock_received_quantity'] = max(0, (int) $item->stock_received_quantity - $quantity);
            $updates['stock_business_unit'] = match (true) {
                $remainingFactoryStock > 0 && $remainingBaliStock > 0 => 'mixed',
                $remainingFactoryStock > 0 => User::UNIT_FACTORY,
                $remainingBaliStock > 0 => User::UNIT_BALI,
                default => null,
            };
        }

        $item->update($updates);
        $order->update([
            'total_order' => max(0, (float) $order->total_order - ((float) $item->price * $quantity)),
        ]);
        $order->synchronizeProductionStatusFromItems();
    }

    private function cancelUnfinishedWork(OrderItem $item, int $quantity, User $user): void
    {
        $quantityToCancel = $quantity;
        $subcontractorAssignments = $item->activeSubcontractorAssignments()
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($subcontractorAssignments as $assignment) {
            if ($quantityToCancel <= 0) {
                break;
            }

            $uncompletedQuantity = max(0, (int) $assignment->assigned_quantity - (int) $assignment->qty_completed);
            $cancelledQuantity = min($uncompletedQuantity, $quantityToCancel);
            if ($cancelledQuantity === 0) {
                continue;
            }

            $newAssignedQuantity = (int) $assignment->assigned_quantity - $cancelledQuantity;
            $assignment->update([
                'assigned_quantity' => $newAssignedQuantity,
                'cancelled_at' => $newAssignedQuantity === 0 ? now() : $assignment->cancelled_at,
            ]);
            $quantityToCancel -= $cancelledQuantity;
        }

        $pieceworkAssignments = PieceworkAssignment::query()
            ->where('order_item_id', $item->id)
            ->whereNull('cancelled_at')
            ->whereNull('completed_at')
            ->withSum('pieceworkItems as produced_quantity', 'quantity')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($pieceworkAssignments as $assignment) {
            if ($quantityToCancel <= 0) {
                break;
            }

            $remainingQuantity = max(
                0,
                (int) $assignment->target_quantity
                    - (int) ($assignment->produced_quantity ?? 0)
                    - (int) $assignment->completed_quantity
                    - (int) $assignment->cancelled_quantity,
            );
            $cancelledQuantity = min($remainingQuantity, $quantityToCancel);
            if ($cancelledQuantity === 0) {
                continue;
            }

            $newCancelledQuantity = (int) $assignment->cancelled_quantity + $cancelledQuantity;
            $updates = [
                'cancelled_quantity' => $newCancelledQuantity,
                'cancelled_by_id' => $user->id,
                'cancellation_note' => 'Sisa tugas disesuaikan dengan jumlah order setelah pembatalan.',
            ];
            if ((int) ($assignment->produced_quantity ?? 0)
                + (int) $assignment->completed_quantity
                + $newCancelledQuantity >= (int) $assignment->target_quantity) {
                $updates['cancelled_at'] = now();
            }

            $assignment->update($updates);
            $quantityToCancel -= $cancelledQuantity;
        }

        $finishingAssignments = FinishingAssignment::query()
            ->where('order_item_id', $item->id)
            ->whereNull('cancelled_at')
            ->whereNull('completed_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($finishingAssignments as $assignment) {
            if ($quantityToCancel <= 0) {
                break;
            }

            $cancelledQuantity = min($assignment->remaining_quantity, $quantityToCancel);
            if ($cancelledQuantity === 0) {
                continue;
            }

            $newCancelledQuantity = (int) $assignment->cancelled_quantity + $cancelledQuantity;
            $updates = [
                'cancelled_quantity' => $newCancelledQuantity,
                'cancelled_by_id' => $user->id,
                'cancellation_note' => 'Sisa tugas disesuaikan dengan jumlah order setelah pembatalan.',
            ];
            if ((int) $assignment->completed_quantity + $newCancelledQuantity >= (int) $assignment->target_quantity) {
                $updates['cancelled_at'] = now();
            }

            $assignment->update($updates);
            $quantityToCancel -= $cancelledQuantity;
        }
    }

    private function lockCatalogProduct(OrderItem $item): Product
    {
        if ($item->product_id === null) {
            throw ValidationException::withMessages([
                'quantity' => 'Produk katalog wajib tersedia untuk mengubah stok.',
            ]);
        }

        return Product::query()->lockForUpdate()->findOrFail($item->product_id);
    }

    private function addProductStock(Product $product, string $unit, int $quantity, User $user): void
    {
        if ($quantity <= 0) {
            return;
        }

        if ($product->factory_sale_price === null) {
            throw ValidationException::withMessages([
                'stock_business_unit' => 'Harga PF katalog wajib diisi sebelum barang dibatalkan masuk stok.',
            ]);
        }

        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('business_unit', $unit)
            ->lockForUpdate()
            ->first();

        if ($stock === null) {
            ProductStock::create([
                'product_id' => $product->id,
                'business_unit' => $unit,
                'quantity' => $quantity,
                'updated_by_id' => $user->id,
            ]);
        } else {
            $stock->increment('quantity', $quantity, ['updated_by_id' => $user->id]);
        }
    }

    private function createInternalStockOrder(OrderItem $sourceItem, int $quantity): void
    {
        $product = $this->lockCatalogProduct($sourceItem);
        $order = Order::create([
            'order_id' => 'STOCK-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'guest_name' => 'Stok Internal PF',
            'guest_source' => null,
            'order_type' => null,
            'phone' => null,
            'total_order' => 0,
            'paid_amount' => 0,
            'deadline' => null,
            'last_edit' => now(),
            'status' => 'None',
            'note' => 'Produksi stok internal dari pembatalan order.',
            'is_stock_order' => true,
        ]);
        $statusImages = [];
        foreach ($sourceItem->status_evidence_images as $path) {
            $statusImages[] = $this->copyAsset($path, 'orders/status');
        }
        $factoryDrawing = $sourceItem->factory_drawing_image ?? $product->factory_mockup;
        $factoryDrawing = filled($factoryDrawing)
            ? $this->copyAsset($factoryDrawing, 'orders/factory-drawings')
            : null;
        $factorySalePrice = $sourceItem->factory_sale_price ?? $product->factory_sale_price;
        $hasPbMockup = $statusImages !== [];

        $order->items()->create([
            'product' => $sourceItem->product,
            'product_id' => $product->id,
            'sku' => $sourceItem->sku ?? $product->sku,
            'category_id' => $sourceItem->category_id ?? $product->category_id,
            'qty' => $quantity,
            'price' => 0,
            'dimensions' => $sourceItem->dimensions ?? $product->dimensions,
            'color' => $sourceItem->color,
            'source' => 'Order',
            'status' => 'Produksi',
            'factory_status' => $hasPbMockup && filled($factoryDrawing) && $factorySalePrice !== null
                ? 'Siap Produksi'
                : 'None',
            'status_image' => $statusImages[0] ?? null,
            'status_images' => $statusImages ?: null,
            'factory_drawing_image' => $factoryDrawing,
            'factory_sale_price' => $factorySalePrice,
        ]);
    }

    private function copyAsset(string $path, string $directory): string
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            throw new RuntimeException('Gambar produk tidak ditemukan untuk order stok internal.');
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $copyPath = $directory.'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
        if (! $disk->copy($path, $copyPath)) {
            throw new RuntimeException('Gambar produk gagal disalin ke order stok internal.');
        }

        return $copyPath;
    }
}
