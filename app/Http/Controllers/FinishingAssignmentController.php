<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\FinishingAssignment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinishingAssignmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessMenu('attendance') && $user->canAccessUnit(User::UNIT_FACTORY), 403);
        abort_unless(! $user->isOwner() || $user->activeBusinessUnit() === User::UNIT_FACTORY, 404);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'target_quantity' => ['required', 'integer', 'min:1'],
            'is_rework' => ['required', 'boolean'],
        ]);
        $employee = Employee::query()
            ->whereKey($validated['employee_id'])
            ->where('business_unit', User::UNIT_FACTORY)
            ->where('employee_type', 'Harian')
            ->where('status', 'Aktif')
            ->firstOrFail();

        DB::transaction(function () use ($validated, $employee, $user): void {
            $initialItem = OrderItem::query()->findOrFail($validated['order_item_id']);
            $order = Order::query()->lockForUpdate()->findOrFail($initialItem->order_id);
            $item = OrderItem::query()
                ->whereKey($validated['order_item_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $isRework = (bool) $validated['is_rework'];
            $isFactoryService = $item->factory_status === 'Service'
                && (int) $item->quality_damaged_quantity > 0;

            if ($order->status === 'Cancel' || (blank($item->factory_drawing_image) && ! $isFactoryService)) {
                throw ValidationException::withMessages([
                    'order_item_id' => 'Produk harus memiliki gambar kerja dan order tidak boleh dibatalkan.',
                ]);
            }

            if ($isFactoryService && ! $isRework) {
                throw ValidationException::withMessages([
                    'is_rework' => 'Produk berstatus Service hanya dapat ditugaskan sebagai Service ulang kepada karyawan Harian.',
                ]);
            }

            $availableQuantity = $isRework
                ? $item->rework_quantity_available
                : $item->finishing_quantity_available;

            if ((int) $validated['target_quantity'] > $availableQuantity) {
                throw ValidationException::withMessages([
                    'target_quantity' => "Jumlah melebihi produk yang tersedia untuk finishing ({$availableQuantity}).",
                ]);
            }

            FinishingAssignment::create([
                'order_item_id' => $item->id,
                'employee_id' => $employee->id,
                'assigned_by_id' => $user->id,
                'target_quantity' => $validated['target_quantity'],
                'is_rework' => $isRework,
                'started_at' => now(),
            ]);
        });

        return back()->with('success', 'Tugas finishing Harian dimulai dan tidak menambah upah harian.');
    }

    public function complete(Request $request, FinishingAssignment $finishingAssignment): RedirectResponse
    {
        $user = $request->user();
        $this->ensureAccess($user, $finishingAssignment);
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($finishingAssignment, $user, $validated): void {
            $initialItem = OrderItem::query()->findOrFail($finishingAssignment->order_item_id);
            $order = Order::query()->lockForUpdate()->findOrFail($initialItem->order_id);
            $item = OrderItem::query()
                ->whereKey($finishingAssignment->order_item_id)
                ->lockForUpdate()
                ->firstOrFail();
            $assignment = FinishingAssignment::query()
                ->whereKey($finishingAssignment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $remainingQuantity = $assignment->remaining_quantity;
            $quantity = (int) $validated['quantity'];

            $isFactoryService = $assignment->is_rework
                && $item->factory_status === 'Service'
                && (int) $item->quality_damaged_quantity > 0;

            if ($order->status === 'Cancel' || (blank($item->factory_drawing_image) && ! $isFactoryService)) {
                throw ValidationException::withMessages([
                    'quantity' => 'Order dibatalkan atau gambar kerja tidak tersedia.',
                ]);
            }

            if ($assignment->completed_at !== null || $assignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'quantity' => 'Tugas finishing yang sudah ditutup tidak dapat diubah.',
                ]);
            }

            if ($quantity > $remainingQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Jumlah selesai melebihi sisa tugas ({$remainingQuantity}).",
                ]);
            }

            if ($assignment->is_rework && $quantity > (int) $item->quality_damaged_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah perbaikan melebihi produk rusak yang masih menunggu pengerjaan ulang.',
                ]);
            }

            $completedQuantity = (int) $assignment->completed_quantity + $quantity;
            $updates = [
                'completed_quantity' => $completedQuantity,
                'completion_note' => $validated['note'] ?? $assignment->completion_note,
            ];
            if ($completedQuantity + (int) $assignment->cancelled_quantity >= (int) $assignment->target_quantity) {
                $updates['completed_at'] = now();
                $updates['completed_by_id'] = $user->id;
            }

            $assignment->update($updates);
            $item->packed_quantity = (int) $item->packed_quantity + $quantity;
            if ($assignment->is_rework) {
                $item->quality_damaged_quantity = max(0, (int) $item->quality_damaged_quantity - $quantity);
            }
            if ($isFactoryService) {
                if ($item->source !== 'Stock') {
                    $item->qty_completed = (int) $item->qty_completed + $quantity;
                }
                $item->status = $item->quality_damaged_quantity > 0 ? 'Produksi' : 'Dalam Perjalanan';
                $item->factory_status = $item->quality_damaged_quantity > 0 ? 'Service' : 'Proses Kirim';
            }
            $item->save();
            if (! $isFactoryService) {
                $item->synchronizeWorkflowStatuses();
            }
            $item->refresh()->addInternalStockOutput($user);
            $order->synchronizeProductionStatusFromItems();
            $description = "{$order->order_id} - {$item->product}: {$quantity} unit";
            if (filled($validated['note'] ?? null)) {
                $description .= ", catatan: {$validated['note']}";
            }
            $order->events()->create([
                'order_item_id' => $item->id,
                'type' => $assignment->is_rework ? 'item_rework_completed' : 'item_finishing_completed',
                'title' => $isFactoryService
                    ? 'Service PF selesai'
                    : ($assignment->is_rework ? 'Finishing ulang selesai' : 'Finishing produk selesai'),
                'description' => $description,
                'occurred_at' => now(),
            ]);
        });

        return back()->with('success', 'Jumlah tugas Harian berhasil dicatat.');
    }

    public function cancel(Request $request, FinishingAssignment $finishingAssignment): RedirectResponse
    {
        $user = $request->user();
        $this->ensureAccess($user, $finishingAssignment);
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($finishingAssignment, $user, $validated): void {
            $initialItem = OrderItem::query()->findOrFail($finishingAssignment->order_item_id);
            Order::query()->lockForUpdate()->findOrFail($initialItem->order_id);
            OrderItem::query()->whereKey($initialItem->id)->lockForUpdate()->firstOrFail();
            $assignment = FinishingAssignment::query()
                ->whereKey($finishingAssignment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $remainingQuantity = $assignment->remaining_quantity;
            $quantity = (int) $validated['quantity'];

            if ($assignment->completed_at !== null || $assignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'quantity' => 'Tugas finishing yang sudah ditutup tidak dapat dibatalkan.',
                ]);
            }

            if ($quantity > $remainingQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Jumlah pembatalan melebihi sisa tugas ({$remainingQuantity}).",
                ]);
            }

            $cancelledQuantity = (int) $assignment->cancelled_quantity + $quantity;
            $updates = [
                'cancelled_quantity' => $cancelledQuantity,
                'cancellation_note' => $validated['note'],
            ];
            if ((int) $assignment->completed_quantity + $cancelledQuantity >= (int) $assignment->target_quantity) {
                $updates['cancelled_at'] = now();
                $updates['cancelled_by_id'] = $user->id;
            }

            $assignment->update($updates);
        });

        return back()->with('success', 'Jumlah tugas finishing berhasil dibatalkan.');
    }

    private function ensureAccess(User $user, FinishingAssignment $finishingAssignment): void
    {
        $employee = $finishingAssignment->employee;
        abort_unless($employee !== null && $employee->business_unit === User::UNIT_FACTORY, 404);
        abort_unless(
            $user->canAccessMenu('attendance')
                && $user->canAccessUnit(User::UNIT_FACTORY)
                && (! $user->isOwner() || $user->activeBusinessUnit() === User::UNIT_FACTORY),
            403,
        );
    }
}
