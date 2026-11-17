<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PieceworkAssignment;
use App\Models\PieceworkItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PieceworkProductionController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        if ($employee->business_unit === User::UNIT_BALI) {
            return $this->storeBaliProduction($request, $employee);
        }

        $user = $request->user();
        abort_unless($user->isFactoryWorkflow() && $user->canAccessMenu('attendance'), 403);
        abort_unless(
            $employee->business_unit === User::UNIT_FACTORY
                && $user->canAccessUnit($employee->business_unit)
                && (! $user->isOwner() || $employee->business_unit === $user->activeBusinessUnit()),
            404,
        );

        $this->normalizePieceworkPrices($request);

        $validated = $request->validate([
            'piecework_items' => ['required', 'array', 'min:1'],
            'piecework_items.*' => ['required', 'array:order_id,order_item_id,assignment_id,product_name,quantity,unit_price,target_deadline'],
            'piecework_items.*.order_id' => ['required', 'integer', 'exists:orders,id'],
            'piecework_items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'piecework_items.*.assignment_id' => ['nullable', 'integer', 'exists:piecework_assignments,id'],
            'piecework_items.*.product_name' => ['required', 'string', 'max:255'],
            'piecework_items.*.quantity' => ['required', 'integer', 'min:1'],
            'piecework_items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'piecework_items.*.target_deadline' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($employee->status !== 'Aktif' || ! $employee->supportsWorkType('Borongan')) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pilih karyawan Pilar Factory aktif berjenis kerja Borongan.',
            ]);
        }

        DB::transaction(function () use ($employee, $validated): void {
            $pieceworkItems = collect($validated['piecework_items']);
            $orderIds = $pieceworkItems->pluck('order_id')->unique()->sort()->values();
            $orderItemIds = $pieceworkItems->pluck('order_item_id')->unique()->sort()->values();
            $assignmentIds = $pieceworkItems->pluck('assignment_id')->filter()->unique()->sort()->values();
            $ordersById = Order::query()
                ->whereKey($orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status'])
                ->keyBy('id');
            $orderItemsById = OrderItem::query()
                ->whereKey($orderItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $assignmentsById = PieceworkAssignment::query()
                ->whereKey($assignmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $producedByAssignment = PieceworkItem::query()
                ->whereIn('piecework_assignment_id', $assignmentIds)
                ->select('piecework_assignment_id')
                ->selectRaw('SUM(quantity) AS produced_quantity')
                ->groupBy('piecework_assignment_id')
                ->pluck('produced_quantity', 'piecework_assignment_id');
            $newQuantitiesByItem = $pieceworkItems
                ->groupBy('order_item_id')
                ->map(fn (Collection $items): int => $items->sum(fn (array $item): int => (int) $item['quantity']));
            $newQuantitiesByAssignment = $pieceworkItems
                ->filter(fn (array $item): bool => filled($item['assignment_id'] ?? null))
                ->groupBy('assignment_id')
                ->map(fn (Collection $items): int => $items->sum(fn (array $item): int => (int) $item['quantity']));

            foreach ($pieceworkItems as $index => $item) {
                $order = $ordersById->get((int) $item['order_id']);
                $orderItem = $orderItemsById->get((int) $item['order_item_id']);
                $assignment = filled($item['assignment_id'] ?? null)
                    ? $assignmentsById->get((int) $item['assignment_id'])
                    : null;

                if (! $order || ! $orderItem || (int) $orderItem->order_id !== (int) $order->id) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.order_item_id" => 'Produk tidak sesuai dengan SPK yang dipilih.',
                    ]);
                }

                if ($orderItem->activeSubcontractorAssignments()->exists()) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.order_item_id" => 'Produk ini sudah dialokasikan ke Subcon dan tidak dapat dikerjakan oleh karyawan borongan PF.',
                    ]);
                }

                if ($order->status === 'Cancel'
                    || $orderItem->factory_status !== 'Produksi'
                    || blank($orderItem->factory_drawing_image)) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.order_item_id" => 'Produk hanya dapat dikerjakan setelah berstatus Produksi dan memiliki gambar kerja Pilar Factory.',
                    ]);
                }

                if (filled($item['assignment_id'] ?? null)
                    && (! $assignment
                        || (int) $assignment->employee_id !== (int) $employee->id
                        || $assignment->completed_at !== null
                        || $assignment->cancelled_at !== null
                        || (int) $assignment->order_item_id !== (int) $orderItem->id)) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.assignment_id" => 'Penugasan tidak berlaku untuk karyawan atau produk ini.',
                    ]);
                }
            }

            foreach ($newQuantitiesByAssignment as $assignmentId => $newQuantity) {
                $assignment = $assignmentsById->get((int) $assignmentId);
                $alreadyProduced = (int) ($producedByAssignment->get($assignmentId) ?? 0);

                if ($alreadyProduced
                    + (int) $assignment->completed_quantity
                    + (int) $assignment->cancelled_quantity
                    + $newQuantity > $assignment->target_quantity) {
                    throw ValidationException::withMessages([
                        'piecework_items' => 'Jumlah melebihi target penugasan borongan untuk '.$assignment->product_name.'.',
                    ]);
                }
            }

            foreach ($newQuantitiesByItem as $orderItemId => $newQuantity) {
                $orderItem = $orderItemsById->get((int) $orderItemId);
                $alreadyProduced = (int) PieceworkItem::query()
                    ->where('order_item_id', $orderItem->id)
                    ->sum('quantity');
                $alreadyCountedQuantity = max($alreadyProduced, (int) $orderItem->qty_completed);

                if ($alreadyCountedQuantity + $newQuantity
                    > max($orderItem->factory_quantity - (int) $orderItem->quality_short_quantity, 0)) {
                    throw ValidationException::withMessages([
                        'piecework_items' => "Jumlah borongan melebihi sisa produk Factory untuk {$orderItem->product}.",
                    ]);
                }
            }

            foreach ($pieceworkItems as $item) {
                $orderItem = $orderItemsById->get((int) $item['order_item_id']);
                $assignment = filled($item['assignment_id'] ?? null)
                    ? $assignmentsById->get((int) $item['assignment_id'])
                    : null;
                $unitPrice = $assignment?->unit_price ?? $item['unit_price'];
                $quantity = (int) $item['quantity'];

                PieceworkItem::create([
                    'employee_id' => $employee->id,
                    'employee_attendance_id' => null,
                    'order_item_id' => $orderItem->id,
                    'piecework_assignment_id' => $assignment?->id,
                    'product_name' => $assignment?->product_name ?? $orderItem->product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => round($quantity * (float) $unitPrice, 2),
                    'target_deadline' => $assignment?->target_deadline ?? $item['target_deadline'],
                ]);
            }

            foreach ($newQuantitiesByItem as $orderItemId => $newQuantity) {
                $orderItem = $orderItemsById->get((int) $orderItemId);
                $orderItem->increment('qty_completed', $newQuantity);
                $orderItem->refresh()->synchronizeWorkflowStatuses();

                $ordersById->get((int) $orderItem->order_id)?->synchronizeProductionStatusFromItems();
            }
        });

        return redirect()
            ->route('admin.attendance.index', ['work_type' => 'Borongan'])
            ->with('success', 'Hasil borongan berhasil dicatat tanpa mengubah absensi.');
    }

    private function storeBaliProduction(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessMenu('attendance'), 403);
        abort_unless(
            $user->canAccessUnit(User::UNIT_BALI)
                && (! $user->isOwner() || $user->activeBusinessUnit() === User::UNIT_BALI),
            404,
        );
        $this->normalizePieceworkPrices($request);

        $validated = $request->validate([
            'piecework_items' => ['required', 'array', 'min:1'],
            'piecework_items.*' => ['required', 'array:assignment_id,product_name,quantity,unit_price,target_deadline'],
            'piecework_items.*.assignment_id' => ['nullable', 'integer', 'exists:piecework_assignments,id'],
            'piecework_items.*.product_name' => ['required', 'string', 'max:255'],
            'piecework_items.*.quantity' => ['required', 'integer', 'min:1'],
            'piecework_items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'piecework_items.*.target_deadline' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($employee->status !== 'Aktif' || ! $employee->supportsWorkType('Borongan')) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pilih karyawan Pilar Bali aktif berjenis kerja Borongan.',
            ]);
        }

        DB::transaction(function () use ($employee, $validated): void {
            $items = collect($validated['piecework_items']);
            $assignmentIds = $items->pluck('assignment_id')->filter()->unique()->sort()->values();
            $assignmentsById = PieceworkAssignment::query()
                ->whereKey($assignmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $newQuantitiesByAssignment = $items
                ->filter(fn (array $item): bool => filled($item['assignment_id'] ?? null))
                ->groupBy('assignment_id')
                ->map(fn (Collection $assignmentItems): int => $assignmentItems
                    ->sum(fn (array $item): int => (int) $item['quantity']));

            foreach ($items as $index => $item) {
                if (blank($item['assignment_id'] ?? null)) {
                    continue;
                }

                $assignment = $assignmentsById->get((int) $item['assignment_id']);
                if (! $assignment
                    || (int) $assignment->employee_id !== (int) $employee->id
                    || $assignment->completed_at !== null
                    || $assignment->cancelled_at !== null
                    || $assignment->order_item_id !== null) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.assignment_id" => 'Penugasan tidak berlaku untuk karyawan ini.',
                    ]);
                }
            }

            foreach ($newQuantitiesByAssignment as $assignmentId => $newQuantity) {
                $assignment = $assignmentsById->get((int) $assignmentId);
                $alreadyProduced = (int) $assignment->pieceworkItems()->sum('quantity');

                if ($alreadyProduced
                    + (int) $assignment->completed_quantity
                    + (int) $assignment->cancelled_quantity
                    + $newQuantity > $assignment->target_quantity) {
                    throw ValidationException::withMessages([
                        'piecework_items' => 'Jumlah melebihi target penugasan borongan untuk '.$assignment->product_name.'.',
                    ]);
                }
            }

            foreach ($items as $item) {
                $assignment = filled($item['assignment_id'] ?? null)
                    ? $assignmentsById->get((int) $item['assignment_id'])
                    : null;
                $unitPrice = $assignment?->unit_price ?? $item['unit_price'];
                $quantity = (int) $item['quantity'];

                PieceworkItem::create([
                    'employee_id' => $employee->id,
                    'piecework_assignment_id' => $assignment?->id,
                    'product_name' => $assignment?->product_name ?? $item['product_name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => round($quantity * (float) $unitPrice, 2),
                    'target_deadline' => $assignment?->target_deadline ?? $item['target_deadline'],
                ]);
            }
        });

        return redirect()
            ->route('admin.attendance.index', ['work_type' => 'Borongan'])
            ->with('success', 'Hasil borongan Pilar Bali berhasil dicatat tanpa membuat absensi.');
    }

    public function updateStandalone(
        Request $request,
        Employee $employee,
        PieceworkItem $pieceworkItem,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user->canAccessMenu('attendance'), 403);
        abort_unless(
            $employee->business_unit === User::UNIT_BALI
                && $user->canAccessUnit(User::UNIT_BALI)
                && (! $user->isOwner() || $user->activeBusinessUnit() === User::UNIT_BALI),
            404,
        );
        abort_unless(
            (int) $pieceworkItem->employee_id === (int) $employee->id
                && $pieceworkItem->employee_attendance_id === null
                && $pieceworkItem->order_item_id === null
                && $pieceworkItem->piecework_assignment_id === null,
            404,
        );

        $this->normalizeUnitPrice($request);
        $validated = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'target_deadline' => ['required', 'date_format:Y-m-d'],
        ]);
        $lineTotal = round((int) $validated['quantity'] * (float) $validated['unit_price'], 2);

        $pieceworkItem->update([
            'product_name' => $validated['product_name'],
            'quantity' => $validated['quantity'],
            'unit_price' => $validated['unit_price'],
            'line_total' => $lineTotal,
            'target_deadline' => $validated['target_deadline'],
        ]);

        return redirect()
            ->route('admin.employees.attendance-history', $employee)
            ->with('success', 'Barang Borongan berhasil diperbarui.');
    }

    private function normalizeUnitPrice(Request $request): void
    {
        $price = $request->input('unit_price');
        if (! is_string($price)) {
            return;
        }

        $price = trim($price);
        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $price) === 1) {
            $request->merge(['unit_price' => str_replace(',', '', $price)]);
        }
    }

    private function normalizePieceworkPrices(Request $request): void
    {
        $items = $request->input('piecework_items');
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (is_array($item) && is_string($item['unit_price'] ?? null)) {
                $price = trim($item['unit_price']);
                if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $price) === 1) {
                    $items[$index]['unit_price'] = str_replace(',', '', $price);
                }
            }
        }

        $request->merge(['piecework_items' => $items]);
    }
}
