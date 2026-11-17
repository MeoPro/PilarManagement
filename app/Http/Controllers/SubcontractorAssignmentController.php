<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubcontractorAssignmentController extends Controller
{
    public function store(Request $request, OrderItem $orderItem): RedirectResponse
    {
        abort_unless($request->user()->isFactoryWorkflow(), 403);

        $validated = $request->validate([
            'subcontractor_id' => ['required', 'integer', 'exists:subcontractors,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'target_deadline' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($request, $orderItem, $validated): void {
            $order = Order::query()->lockForUpdate()->findOrFail($orderItem->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($orderItem->id);

            if ($order->status === 'Cancel') {
                throw ValidationException::withMessages([
                    'subcontractor_id' => 'Order yang dibatalkan tidak dapat dialokasikan ke subcon.',
                ]);
            }

            $subcontractor = Subcontractor::query()->findOrFail($validated['subcontractor_id']);
            if (! $subcontractor->is_active) {
                throw ValidationException::withMessages([
                    'subcontractor_id' => 'Subkontraktor nonaktif tidak dapat menerima pekerjaan baru.',
                ]);
            }

            if (in_array($item->factory_status, ['Distribusi', 'Ready'], true)) {
                throw ValidationException::withMessages([
                    'quantity' => 'Produk yang sudah selesai di Factory tidak dapat dialokasikan lagi.',
                ]);
            }

            $availableQuantity = max(0, $item->factory_quantity - (int) $item->qty_completed);
            if ((int) $validated['quantity'] > $availableQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Jumlah melebihi sisa pekerjaan Factory ({$availableQuantity}).",
                ]);
            }

            $previousStatus = $item->status;
            $assignment = $item->subcontractorAssignments()->create([
                'subcontractor_id' => $subcontractor->id,
                'created_by_id' => $request->user()->id,
                'assigned_quantity' => (int) $validated['quantity'],
                'target_deadline' => $validated['target_deadline'] ?? $order->deadline,
                'qty_completed' => 0,
                'status' => 'Produksi',
            ]);
            $item->synchronizeStatusFromWorkAllocations();
            $item->refresh();
            $order->update(['last_edit' => now()]);
            $order->events()->create([
                'order_item_id' => $item->id,
                'type' => 'item_subcontractor_assigned',
                'title' => 'Jumlah produk dialokasikan ke subcon',
                'description' => "{$order->order_id} - {$item->product}: {$assignment->assigned_quantity} unit ke {$subcontractor->name}",
                'occurred_at' => now(),
            ]);

            if ($previousStatus !== $item->status) {
                $this->recordItemStatusEvent($order, $item, $previousStatus, $item->status);
            }

            $order->synchronizeProductionStatusFromItems();
        });

        return back()->with('success', 'Jumlah produk berhasil dialokasikan ke subcon.');
    }

    public function storeOrder(Request $request, Order $order): RedirectResponse
    {
        abort_unless(
            $request->user()->isFactoryWorkflow()
                && $request->user()->canAccessMenu('subcontractors'),
            403,
        );

        $validated = $request->validate([
            'subcontractor_id' => ['required', 'integer', 'exists:subcontractors,id'],
            'target_deadline' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($request, $order, $validated): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status === 'Cancel') {
                throw ValidationException::withMessages([
                    'subcontractor_id' => 'Order yang dibatalkan tidak dapat dialokasikan ke subcon.',
                ]);
            }

            $subcontractor = Subcontractor::query()->findOrFail($validated['subcontractor_id']);
            if (! $subcontractor->is_active) {
                throw ValidationException::withMessages([
                    'subcontractor_id' => 'Subkontraktor nonaktif tidak dapat menerima pekerjaan baru.',
                ]);
            }

            $createdAssignments = 0;
            foreach ($lockedOrder->items()->lockForUpdate()->get() as $item) {
                if (in_array($item->factory_status, ['Distribusi', 'Ready'], true)) {
                    continue;
                }

                $availableQuantity = max(0, $item->factory_quantity - (int) $item->qty_completed);
                if ($availableQuantity === 0) {
                    continue;
                }

                $assignment = $item->subcontractorAssignments()->create([
                    'subcontractor_id' => $subcontractor->id,
                    'created_by_id' => $request->user()->id,
                    'assigned_quantity' => $availableQuantity,
                    'target_deadline' => $validated['target_deadline'] ?? $lockedOrder->deadline,
                    'qty_completed' => 0,
                    'status' => 'Produksi',
                ]);
                $item->synchronizeStatusFromWorkAllocations();
                $lockedOrder->events()->create([
                    'order_item_id' => $item->id,
                    'type' => 'item_subcontractor_assigned',
                    'title' => 'Jumlah produk dialokasikan ke subcon',
                    'description' => "{$lockedOrder->order_id} - {$item->product}: {$assignment->assigned_quantity} unit ke {$subcontractor->name}",
                    'occurred_at' => now(),
                ]);
                $createdAssignments++;
            }

            if ($createdAssignments === 0) {
                throw ValidationException::withMessages([
                    'subcontractor_id' => 'Tidak ada sisa produk Factory yang dapat dialokasikan ke subcon.',
                ]);
            }

            $lockedOrder->update(['last_edit' => now()]);
            $lockedOrder->synchronizeProductionStatusFromItems();
        });

        return back()->with('success', 'Sisa produk order berhasil dialokasikan ke subcon.');
    }

    public function update(Request $request, SubcontractorAssignment $subcontractorAssignment): RedirectResponse
    {
        abort_unless($request->user()->isFactoryWorkflow(), 403);

        $validated = $request->validate([
            'qty_completed' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['Produksi', 'Proses Kirim', 'Distribusi'])],
            'comment' => ['nullable', 'string', 'max:2000'],
            'target_deadline' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($subcontractorAssignment, $validated): void {
            $orderId = OrderItem::query()
                ->whereKey($subcontractorAssignment->order_item_id)
                ->value('order_id');
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($subcontractorAssignment->order_item_id);
            $assignment = SubcontractorAssignment::query()
                ->lockForUpdate()
                ->findOrFail($subcontractorAssignment->id);

            if ($assignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'status' => 'Alokasi yang sudah dibatalkan tidak dapat diubah.',
                ]);
            }

            if ($order->status === 'Cancel') {
                throw ValidationException::withMessages([
                    'status' => 'Order yang dibatalkan tidak dapat diubah.',
                ]);
            }

            $completed = (int) $validated['qty_completed'];
            if ($completed > (int) $assignment->assigned_quantity) {
                throw ValidationException::withMessages([
                    'qty_completed' => 'Progres tidak boleh melebihi jumlah yang dialokasikan.',
                ]);
            }

            $previousOverallStatus = $item->status;
            $previousStatus = $assignment->status;
            $previousCompleted = (int) $assignment->qty_completed;
            $previousDeadline = $assignment->target_deadline?->toDateString();
            $targetDeadline = array_key_exists('target_deadline', $validated)
                ? $validated['target_deadline']
                : $previousDeadline;
            $nextStatus = $validated['status'];
            $reworkComment = trim((string) ($validated['comment'] ?? ''));
            $isReturningToProduction = $previousStatus === 'Distribusi' && $nextStatus === 'Produksi';

            if ($isReturningToProduction) {
                if ($reworkComment === '') {
                    throw ValidationException::withMessages([
                        'comment' => 'Komentar wajib diisi saat produk dikembalikan ke Produksi.',
                    ]);
                }

                $completed = 0;
            }

            if (! $isReturningToProduction) {
                if ($nextStatus === 'Produksi' && $completed === (int) $assignment->assigned_quantity) {
                    $nextStatus = 'Proses Kirim';
                } elseif (
                    $nextStatus === 'Proses Kirim'
                    && $completed < (int) $assignment->assigned_quantity
                    && $previousStatus === 'Proses Kirim'
                ) {
                    $nextStatus = 'Produksi';
                }

                if (
                    in_array($nextStatus, ['Proses Kirim', 'Distribusi'], true)
                    && $completed < (int) $assignment->assigned_quantity
                ) {
                    throw ValidationException::withMessages([
                        'qty_completed' => 'Selesaikan semua jumlah alokasi sebelum melanjutkan ke proses kirim.',
                    ]);
                }

                if (
                    $nextStatus === 'Distribusi'
                    && $nextStatus !== $previousStatus
                    && $previousStatus !== 'Proses Kirim'
                ) {
                    throw ValidationException::withMessages([
                        'status' => 'Ubah status ke Proses Kirim sebelum melanjutkan ke Distribusi.',
                    ]);
                }
            }

            if (
                $previousStatus === $nextStatus
                && $previousCompleted === $completed
                && $previousDeadline === $targetDeadline
            ) {
                return;
            }

            $assignment->update([
                'qty_completed' => $completed,
                'status' => $nextStatus,
                'target_deadline' => $targetDeadline,
            ]);
            $item->synchronizeStatusFromWorkAllocations();
            $item->refresh();
            $order->update(['last_edit' => now()]);

            if ($previousStatus !== $nextStatus) {
                $order->events()->create([
                    'order_item_id' => $item->id,
                    'type' => 'item_subcontractor_status_changed',
                    'title' => 'Status pekerjaan subcon berubah',
                    'description' => "{$order->order_id} - {$item->product} ({$assignment->subcontractor->name}): {$previousStatus} -> {$nextStatus}",
                    'occurred_at' => now(),
                ]);
            }

            if ($previousCompleted !== $completed) {
                $order->events()->create([
                    'order_item_id' => $item->id,
                    'type' => 'item_subcontractor_progress_changed',
                    'title' => 'Progres subcon diperbarui',
                    'description' => "{$order->order_id} - {$item->product} ({$assignment->subcontractor->name}): {$previousCompleted} -> {$completed} dari {$assignment->assigned_quantity}",
                    'occurred_at' => now(),
                ]);
            }

            if ($previousDeadline !== $targetDeadline) {
                $order->events()->create([
                    'order_item_id' => $item->id,
                    'type' => 'item_subcontractor_deadline_changed',
                    'title' => 'Deadline pekerjaan subcon diperbarui',
                    'description' => "{$order->order_id} - {$item->product} ({$assignment->subcontractor->name}): "
                        .($previousDeadline ?? 'Tanpa deadline').' -> '.($targetDeadline ?? 'Tanpa deadline'),
                    'occurred_at' => now(),
                ]);
            }

            if ($isReturningToProduction) {
                $order->events()->create([
                    'order_item_id' => $item->id,
                    'type' => 'item_rework_requested',
                    'title' => 'Pekerjaan subcon dikembalikan ke Produksi',
                    'description' => $reworkComment,
                    'occurred_at' => now(),
                ]);
            }

            if ($previousOverallStatus !== $item->status) {
                $this->recordItemStatusEvent($order, $item, $previousOverallStatus, $item->status);
            }

            $order->synchronizeProductionStatusFromItems();
        });

        return back()->with('success', 'Progres pekerjaan subcon berhasil diperbarui.');
    }

    public function cancel(SubcontractorAssignment $subcontractorAssignment): RedirectResponse
    {
        abort_unless(request()->user()->isFactoryWorkflow(), 403);

        DB::transaction(function () use ($subcontractorAssignment): void {
            $orderId = OrderItem::query()
                ->whereKey($subcontractorAssignment->order_item_id)
                ->value('order_id');
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($subcontractorAssignment->order_item_id);
            $assignment = SubcontractorAssignment::query()
                ->lockForUpdate()
                ->findOrFail($subcontractorAssignment->id);

            if ($assignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'assignment' => 'Alokasi ini sudah dibatalkan.',
                ]);
            }

            if ($order->status === 'Cancel') {
                throw ValidationException::withMessages([
                    'assignment' => 'Order yang dibatalkan tidak dapat mengubah alokasi.',
                ]);
            }

            if (
                $assignment->status !== 'Produksi'
                || $assignment->qty_completed >= $assignment->assigned_quantity
            ) {
                throw ValidationException::withMessages([
                    'assignment' => 'Sisa alokasi hanya dapat dikembalikan saat subcon masih dalam Produksi.',
                ]);
            }

            $previousOverallStatus = $item->status;
            $returnedQuantity = (int) $assignment->assigned_quantity - (int) $assignment->qty_completed;
            if ($assignment->qty_completed === 0) {
                $assignment->update(['cancelled_at' => now()]);
            } else {
                $assignment->update([
                    'assigned_quantity' => $assignment->qty_completed,
                    'status' => 'Proses Kirim',
                ]);
            }
            $item->synchronizeStatusFromWorkAllocations();
            $item->refresh();
            $order->update(['last_edit' => now()]);
            $order->events()->create([
                'order_item_id' => $item->id,
                'type' => 'item_subcontractor_quantity_returned',
                'title' => 'Sisa produk dikembalikan ke Factory',
                'description' => "{$order->order_id} - {$item->product}: {$returnedQuantity} unit dikembalikan dari {$assignment->subcontractor->name} ke Factory",
                'occurred_at' => now(),
            ]);

            if ($previousOverallStatus !== $item->status) {
                $this->recordItemStatusEvent($order, $item, $previousOverallStatus, $item->status);
            }

            $order->synchronizeProductionStatusFromItems();
        });

        return back()->with('success', 'Sisa jumlah berhasil dikembalikan ke antrean Factory.');
    }

    private function recordItemStatusEvent(Order $order, OrderItem $item, string $previousStatus, string $nextStatus): void
    {
        $order->events()->create([
            'order_item_id' => $item->id,
            'type' => 'item_status_changed',
            'title' => 'Status produk berubah',
            'description' => "{$order->order_id} - {$item->product}: {$previousStatus} -> {$nextStatus}",
            'occurred_at' => now(),
        ]);
    }
}
