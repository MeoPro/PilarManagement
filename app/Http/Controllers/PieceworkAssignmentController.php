<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PieceworkAssignment;
use App\Models\PieceworkItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PieceworkAssignmentController extends Controller
{
    public function storeForEmployee(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessMenu('attendance'), 403);
        abort_unless($employee->business_unit === User::UNIT_BALI, 404);
        abort_unless($user->canAccessUnit($employee->business_unit), 404);
        $this->normalizeUnitPrice($request);

        $validated = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'target_quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'target_deadline' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now($user->timezone())->toDateString()],
        ]);

        if ($employee->status !== 'Aktif' || ! $employee->supportsWorkType('Borongan')) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pilih karyawan aktif berjenis kerja Borongan.',
            ]);
        }

        PieceworkAssignment::create([
            'employee_id' => $employee->id,
            'assigned_by_id' => $user->id,
            'product_name' => $validated['product_name'],
            'target_quantity' => $validated['target_quantity'],
            'unit_price' => $validated['unit_price'],
            'target_deadline' => $validated['target_deadline'],
            'completed_at' => null,
            'completed_by_id' => null,
        ]);

        return redirect()
            ->route('admin.attendance.index', ['work_type' => 'Borongan'])
            ->with('success', 'Tugas borongan berhasil ditambahkan.');
    }

    public function store(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isFactoryWorkflow() && $user->canAccessMenu('order_products'), 403);
        $localToday = now($user->timezone())->toDateString();
        $this->normalizeUnitPrice($request);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'target_quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'target_deadline' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$localToday],
        ]);
        $employee = Employee::query()
            ->whereKey($validated['employee_id'])
            ->where('business_unit', User::UNIT_FACTORY)
            ->where('status', 'Aktif')
            ->firstOrFail();

        if (! $employee->supportsWorkType('Borongan')) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pilih karyawan Factory berjenis kerja Borongan.',
            ]);
        }

        DB::transaction(function () use ($orderItem, $employee, $validated, $user): void {
            $order = Order::query()->lockForUpdate()->findOrFail($orderItem->order_id);
            $lockedItem = OrderItem::query()
                ->whereKey($orderItem->id)
                ->withSum(['services as pending_service_quantity' => fn ($query) => $query->where('status', 'Service')], 'quantity')
                ->lockForUpdate()
                ->firstOrFail();
            $this->createFactoryPieceworkAssignment(
                $order,
                $lockedItem,
                $employee,
                $user,
                (int) $validated['target_quantity'],
                (float) $validated['unit_price'],
                $validated['target_deadline'],
                'target_quantity',
                'target_deadline',
            );
        });

        return redirect()
            ->route('orders.show', $orderItem->order_id)
            ->with('success', 'Penugasan borongan berhasil ditambahkan.');
    }

    public function storeForOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isFactoryWorkflow() && $user->canAccessMenu('order_products'), 403);
        $localToday = now($user->timezone())->toDateString();
        $this->normalizeAssignmentUnitPrices($request);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*' => ['required', 'array:order_item_id,target_quantity,unit_price,target_deadline'],
            'assignments.*.order_item_id' => ['required', 'integer', 'distinct', 'exists:order_items,id'],
            'assignments.*.target_quantity' => ['required', 'integer', 'min:1'],
            'assignments.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'assignments.*.target_deadline' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$localToday],
        ]);
        $employee = Employee::query()
            ->whereKey($validated['employee_id'])
            ->where('business_unit', User::UNIT_FACTORY)
            ->where('status', 'Aktif')
            ->firstOrFail();

        if (! $employee->supportsWorkType('Borongan')) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pilih karyawan Factory berjenis kerja Borongan.',
            ]);
        }

        DB::transaction(function () use ($order, $employee, $validated, $user): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            foreach ($validated['assignments'] as $index => $assignment) {
                $lockedItem = OrderItem::query()
                    ->where('order_id', $lockedOrder->id)
                    ->whereKey($assignment['order_item_id'])
                    ->withSum(['services as pending_service_quantity' => fn ($query) => $query->where('status', 'Service')], 'quantity')
                    ->lockForUpdate()
                    ->first();

                if (! $lockedItem instanceof OrderItem) {
                    throw ValidationException::withMessages([
                        "assignments.{$index}.order_item_id" => 'Pilih produk dari order ini.',
                    ]);
                }

                $this->createFactoryPieceworkAssignment(
                    $lockedOrder,
                    $lockedItem,
                    $employee,
                    $user,
                    (int) $assignment['target_quantity'],
                    (float) $assignment['unit_price'],
                    $assignment['target_deadline'],
                    "assignments.{$index}.target_quantity",
                    "assignments.{$index}.target_deadline",
                );
            }
        });

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Penugasan borongan berhasil ditambahkan.');
    }

    private function createFactoryPieceworkAssignment(
        Order $order,
        OrderItem $orderItem,
        Employee $employee,
        User $user,
        int $targetQuantity,
        float $unitPrice,
        string $targetDeadline,
        string $quantityErrorKey,
        string $deadlineErrorKey,
    ): void {
        if ($order->status === 'Cancel') {
            throw ValidationException::withMessages([
                $quantityErrorKey => 'Order yang dibatalkan tidak dapat ditugaskan ke borongan PF.',
            ]);
        }

        $orderDeadline = $order->factory_deadline ?? $order->deadline;
        if ($orderDeadline !== null && $targetDeadline > $orderDeadline->toDateString()) {
            throw ValidationException::withMessages([
                $deadlineErrorKey => 'Deadline penugasan borongan tidak boleh melebihi deadline order ('.$orderDeadline->format('d/m/Y').').',
            ]);
        }

        if ($orderItem->activeSubcontractorAssignments()->exists()) {
            throw ValidationException::withMessages([
                $quantityErrorKey => 'Produk ini sudah dialokasikan ke Subcon dan tidak dapat ditugaskan ke borongan PF.',
            ]);
        }

        if (! $orderItem->allowsRegularProductionAssignment()
            || blank($orderItem->factory_drawing_image)) {
            throw ValidationException::withMessages([
                $quantityErrorKey => 'Produk hanya dapat ditugaskan setelah berstatus Siap Produksi dan memiliki gambar kerja Pilar Factory.',
            ]);
        }

        if (
            $orderItem->source === 'Stock'
            && ((int) $orderItem->quality_unchecked_quantity > 0
                || (int) $orderItem->service_quantity > 0
                || (int) $orderItem->packed_quantity > 0)
        ) {
            throw ValidationException::withMessages([
                $quantityErrorKey => 'Selesaikan pemeriksaan PB untuk seluruh Stock sebelum menugaskan produk ke borongan PF.',
            ]);
        }

        $openAssignments = PieceworkAssignment::query()
            ->where('order_item_id', $orderItem->id)
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->where('is_rework', false)
            ->withSum('pieceworkItems as produced_quantity', 'quantity')
            ->get(['id', 'target_quantity', 'completed_quantity', 'cancelled_quantity', 'is_rework']);
        $reservedQuantity = $openAssignments->sum(
            fn (PieceworkAssignment $assignment): int => max(
                (int) $assignment->target_quantity
                    - (int) ($assignment->produced_quantity ?? 0)
                    - (int) $assignment->completed_quantity
                    - (int) $assignment->cancelled_quantity,
                0,
            ),
        );
        $availableQuantity = max($orderItem->factory_production_qty_remaining - $reservedQuantity, 0);

        if ($targetQuantity > $availableQuantity) {
            throw ValidationException::withMessages([
                $quantityErrorKey => "Jumlah penugasan melebihi sisa produk Factory ({$availableQuantity}).",
            ]);
        }

        PieceworkAssignment::create([
            'employee_id' => $employee->id,
            'order_item_id' => $orderItem->id,
            'assigned_by_id' => $user->id,
            'product_name' => $orderItem->product,
            'target_quantity' => $targetQuantity,
            'unit_price' => $unitPrice,
            'target_deadline' => $targetDeadline,
            'is_rework' => false,
            'completed_at' => null,
            'completed_by_id' => null,
        ]);
        if ($orderItem->factory_status !== 'Service') {
            $orderItem->update(['factory_status' => 'Produksi']);
        }
    }

    public function update(Request $request, PieceworkAssignment $pieceworkAssignment): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isFactoryWorkflow() && $user->canAccessMenu('order_products'), 403);
        abort_if($pieceworkAssignment->employee === null || $pieceworkAssignment->employee->business_unit !== User::UNIT_FACTORY, 404);
        $this->normalizeUnitPrice($request);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'target_quantity' => ['required', 'integer', 'min:1'],
            'target_deadline' => ['nullable', 'date_format:Y-m-d'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);

        $employee = Employee::query()
            ->whereKey($validated['employee_id'])
            ->where('business_unit', User::UNIT_FACTORY)
            ->where('status', 'Aktif')
            ->firstOrFail();

        if (! $employee->supportsWorkType('Borongan')) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pilih karyawan Factory berjenis kerja Borongan.',
            ]);
        }

        DB::transaction(function () use ($pieceworkAssignment, $employee, $validated): void {
            $orderItem = OrderItem::query()->lockForUpdate()->findOrFail($pieceworkAssignment->order_item_id);
            $lockedAssignment = PieceworkAssignment::query()->lockForUpdate()->findOrFail($pieceworkAssignment->id);

            if ($lockedAssignment->completed_at !== null || $lockedAssignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Penugasan yang sudah selesai tidak dapat dipindah.',
                ]);
            }

            if ($orderItem->activeSubcontractorAssignments()->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Produk ini sudah dialokasikan ke Subcon dan tidak dapat dipindah ke borongan PF lain.',
                ]);
            }

            $openAssignments = PieceworkAssignment::query()
                ->where('order_item_id', $orderItem->id)
                ->whereNull('completed_at')
                ->whereNull('cancelled_at')
                ->whereKeyNot($lockedAssignment->id)
                ->where('is_rework', $lockedAssignment->is_rework)
                ->withSum('pieceworkItems as produced_quantity', 'quantity')
                ->get(['id', 'target_quantity', 'completed_quantity', 'cancelled_quantity', 'is_rework']);
            $reservedQuantity = $openAssignments->sum(
                fn (PieceworkAssignment $assignment): int => max(
                    (int) $assignment->target_quantity
                        - (int) ($assignment->produced_quantity ?? 0)
                        - (int) $assignment->completed_quantity
                        - (int) $assignment->cancelled_quantity,
                    0,
                ),
            );
            $remainingFactoryQuantity = $lockedAssignment->is_rework
                ? (int) $orderItem->factory_qty_remaining
                : (int) $orderItem->factory_production_qty_remaining;
            $availableQuantity = max($remainingFactoryQuantity - $reservedQuantity, 0);
            $targetQuantity = (int) $validated['target_quantity'];
            $alreadyProduced = (int) $lockedAssignment->pieceworkItems()->sum('quantity');
            $completedQuantity = (int) $lockedAssignment->completed_quantity;
            $cancelledQuantity = (int) $lockedAssignment->cancelled_quantity;

            if ($targetQuantity < $alreadyProduced + $completedQuantity + $cancelledQuantity) {
                throw ValidationException::withMessages([
                    'target_quantity' => 'Jumlah target tidak boleh kurang dari jumlah selesai dan dibatalkan.',
                ]);
            }

            if ($targetQuantity - $alreadyProduced - $completedQuantity - $cancelledQuantity > $availableQuantity) {
                throw ValidationException::withMessages([
                    'target_quantity' => "Jumlah penugasan melebihi sisa produk Factory ({$availableQuantity}).",
                ]);
            }

            $updates = [
                'employee_id' => $employee->id,
                'target_quantity' => $targetQuantity,
                'target_deadline' => $validated['target_deadline'] ?? $lockedAssignment->target_deadline,
            ];
            if (array_key_exists('unit_price', $validated)) {
                $updates['unit_price'] = $validated['unit_price'];
            }

            $lockedAssignment->update($updates);
        });

        return back()->with('success', 'Penugasan borongan berhasil diperbarui.');
    }

    public function updateRate(Request $request, PieceworkAssignment $pieceworkAssignment): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isFactoryWorkflow() && $user->canAccessMenu('order_products'), 403);
        abort_if(
            $pieceworkAssignment->order_item_id === null
                || $pieceworkAssignment->employee?->business_unit !== User::UNIT_FACTORY,
            404,
        );
        $this->normalizeUnitPrice($request);

        $validated = $request->validate([
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);

        DB::transaction(function () use ($pieceworkAssignment, $validated): void {
            $lockedAssignment = PieceworkAssignment::query()
                ->lockForUpdate()
                ->findOrFail($pieceworkAssignment->id);
            $completedQuantity = (int) $lockedAssignment->completed_quantity;

            if ($completedQuantity < 1) {
                throw ValidationException::withMessages([
                    'unit_price' => 'Tidak ada jumlah selesai yang perlu dicatat ke penggajian.',
                ]);
            }

            $lockedAssignment->update([
                'unit_price' => $validated['unit_price'],
            ]);
            $this->recordPieceworkPayment($lockedAssignment, $completedQuantity);
            $lockedAssignment->update(['completed_quantity' => 0]);
        });

        return redirect()
            ->route('orders.show', $pieceworkAssignment->orderItem->order_id)
            ->with('success', 'Upah borongan berhasil dicatat ke penggajian.');
    }

    public function updateFromAttendance(Request $request, PieceworkAssignment $pieceworkAssignment): RedirectResponse
    {
        $user = $request->user();
        $employee = $this->ensureAttendanceAccess($user, $pieceworkAssignment);
        $this->normalizeUnitPrice($request);
        $isBaliAssignment = $employee->business_unit === User::UNIT_BALI;

        $validated = $request->validate([
            'product_name' => $isBaliAssignment
                ? ['required', 'string', 'max:255']
                : ['prohibited'],
            'target_quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'target_deadline' => ['required', 'date_format:Y-m-d'],
        ]);

        DB::transaction(function () use ($pieceworkAssignment, $validated, $isBaliAssignment): void {
            $orderItem = null;
            if (! $isBaliAssignment) {
                if ($pieceworkAssignment->order_item_id === null) {
                    abort(404);
                }

                $orderItem = OrderItem::query()
                    ->lockForUpdate()
                    ->findOrFail($pieceworkAssignment->order_item_id);
            }

            $lockedAssignment = PieceworkAssignment::query()
                ->lockForUpdate()
                ->findOrFail($pieceworkAssignment->id);

            if ($lockedAssignment->completed_at !== null || $lockedAssignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'target_quantity' => 'Tugas yang sudah ditutup tidak dapat diedit.',
                ]);
            }

            $alreadyProduced = (int) $lockedAssignment->pieceworkItems()->sum('quantity');
            $completedQuantity = (int) $lockedAssignment->completed_quantity;
            $cancelledQuantity = (int) $lockedAssignment->cancelled_quantity;
            $targetQuantity = (int) $validated['target_quantity'];

            if ($targetQuantity < $alreadyProduced + $completedQuantity + $cancelledQuantity) {
                throw ValidationException::withMessages([
                    'target_quantity' => 'Jumlah target tidak boleh kurang dari jumlah selesai dan dibatalkan.',
                ]);
            }

            if (! $isBaliAssignment) {
                if ($orderItem->activeSubcontractorAssignments()->exists()) {
                    throw ValidationException::withMessages([
                        'target_quantity' => 'Produk ini sudah dialokasikan ke Subcon dan tidak dapat diubah.',
                    ]);
                }

                $otherAssignments = PieceworkAssignment::query()
                    ->where('order_item_id', $orderItem->id)
                    ->whereNull('completed_at')
                    ->whereNull('cancelled_at')
                    ->whereKeyNot($lockedAssignment->id)
                    ->where('is_rework', $lockedAssignment->is_rework)
                    ->withSum('pieceworkItems as produced_quantity', 'quantity')
                    ->get(['id', 'target_quantity', 'completed_quantity', 'cancelled_quantity', 'is_rework']);
                $reservedQuantity = $otherAssignments->sum(
                    fn (PieceworkAssignment $assignment): int => max(
                        (int) $assignment->target_quantity
                            - (int) ($assignment->produced_quantity ?? 0)
                            - (int) $assignment->completed_quantity
                            - (int) $assignment->cancelled_quantity,
                        0,
                    ),
                );
                $remainingFactoryQuantity = $lockedAssignment->is_rework
                    ? (int) $orderItem->factory_qty_remaining
                    : (int) $orderItem->factory_production_qty_remaining;
                $availableQuantity = max($remainingFactoryQuantity - $reservedQuantity, 0);

                if ($targetQuantity - $alreadyProduced - $completedQuantity - $cancelledQuantity > $availableQuantity) {
                    throw ValidationException::withMessages([
                        'target_quantity' => "Jumlah penugasan melebihi sisa produk Factory ({$availableQuantity}).",
                    ]);
                }
            }

            $updates = [
                'target_quantity' => $targetQuantity,
                'unit_price' => $validated['unit_price'] ?? null,
                'target_deadline' => $validated['target_deadline'],
            ];
            if ($isBaliAssignment) {
                $updates['product_name'] = $validated['product_name'];
            }

            $lockedAssignment->update($updates);
        });

        return back()->with('success', 'Tugas Borongan berhasil diperbarui.');
    }

    public function cancelQuantity(Request $request, PieceworkAssignment $pieceworkAssignment): RedirectResponse
    {
        $user = $request->user();
        $this->ensureAttendanceAccess($user, $pieceworkAssignment);
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($pieceworkAssignment, $user, $validated): void {
            $lockedAssignment = PieceworkAssignment::query()
                ->lockForUpdate()
                ->findOrFail($pieceworkAssignment->id);

            if ($lockedAssignment->completed_at !== null || $lockedAssignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'quantity' => 'Tugas yang sudah ditutup tidak dapat dibatalkan.',
                ]);
            }

            $alreadyProduced = (int) $lockedAssignment->pieceworkItems()->sum('quantity');
            $completedQuantity = (int) $lockedAssignment->completed_quantity;
            $cancelledQuantity = (int) $lockedAssignment->cancelled_quantity;
            $remainingQuantity = max(
                (int) $lockedAssignment->target_quantity
                    - $alreadyProduced
                    - $completedQuantity
                    - $cancelledQuantity,
                0,
            );
            $quantityToCancel = (int) $validated['quantity'];

            if ($quantityToCancel > $remainingQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Jumlah yang dibatalkan melebihi sisa target ({$remainingQuantity}).",
                ]);
            }

            $newCancelledQuantity = $cancelledQuantity + $quantityToCancel;
            $updates = [
                'cancelled_quantity' => $newCancelledQuantity,
                'cancellation_note' => $validated['note'],
            ];
            if ($alreadyProduced + $completedQuantity + $newCancelledQuantity >= (int) $lockedAssignment->target_quantity) {
                $updates['cancelled_at'] = now();
                $updates['cancelled_by_id'] = $user->id;
            }

            $lockedAssignment->update($updates);
        });

        return back()->with('success', 'Jumlah target Borongan berhasil dibatalkan.');
    }

    public function complete(Request $request, PieceworkAssignment $pieceworkAssignment): RedirectResponse
    {
        $user = $request->user();
        $employee = $pieceworkAssignment->employee;
        abort_unless($employee, 404);
        if ($employee->business_unit === User::UNIT_FACTORY) {
            abort_unless(
                ($user->isFactoryWorkflow() && $user->canAccessMenu('order_products'))
                    || ($user->canAccessMenu('attendance') && $user->canAccessUnit(User::UNIT_FACTORY)),
                403,
            );
            abort_if($pieceworkAssignment->order_item_id === null, 404);
        } else {
            abort_unless($employee->business_unit === User::UNIT_BALI, 404);
            abort_unless($user->canAccessMenu('attendance') && $user->canAccessUnit($employee->business_unit), 403);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($pieceworkAssignment, $user, $validated, $employee): void {
            $lockedItem = null;
            $lockedOrder = null;
            if ($employee->business_unit === User::UNIT_FACTORY) {
                $initialItem = OrderItem::query()->findOrFail($pieceworkAssignment->order_item_id);
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($initialItem->order_id);
                $lockedItem = OrderItem::query()
                    ->whereKey($pieceworkAssignment->order_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            $lockedAssignment = PieceworkAssignment::query()
                ->lockForUpdate()
                ->findOrFail($pieceworkAssignment->id);

            if ($lockedAssignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'piecework_assignment' => 'Tugas yang dibatalkan tidak dapat ditandai selesai.',
                ]);
            }

            $alreadyProduced = (int) $lockedAssignment->pieceworkItems()->sum('quantity');
            $completedQuantity = (int) $lockedAssignment->completed_quantity;
            $cancelledQuantity = (int) $lockedAssignment->cancelled_quantity;
            $remainingQuantity = max(
                (int) $lockedAssignment->target_quantity
                    - $alreadyProduced
                    - $completedQuantity
                    - $cancelledQuantity,
                0,
            );
            $quantityToComplete = (int) $validated['quantity'];

            if ($quantityToComplete > $remainingQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Jumlah yang diselesaikan melebihi sisa target ({$remainingQuantity}).",
                ]);
            }

            if ($remainingQuantity > 0 && $quantityToComplete === 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah yang diselesaikan minimal 1.',
                ]);
            }

            if (($completedQuantity > 0 || $quantityToComplete > 0) && $lockedAssignment->unit_price === null) {
                throw ValidationException::withMessages([
                    'quantity' => 'Isi harga borongan per unit pada penugasan sebelum mencatat hasil selesai.',
                ]);
            }

            if ($lockedAssignment->completed_at === null) {
                $updates = [
                    'completed_quantity' => 0,
                    'completion_note' => $validated['note'] ?? $lockedAssignment->completion_note,
                ];

                if ($alreadyProduced + $completedQuantity + $quantityToComplete + $cancelledQuantity >= (int) $lockedAssignment->target_quantity) {
                    $updates['completed_at'] = now();
                    $updates['completed_by_id'] = $user->id;
                }

                $lockedAssignment->update($updates);
                $this->recordPieceworkPayment($lockedAssignment, $completedQuantity);
                $this->recordPieceworkPayment($lockedAssignment, $quantityToComplete);

                if ($lockedItem !== null) {
                    $canCompleteRegularProduction = $lockedItem->allowsRegularProductionAssignment()
                        && (! $lockedAssignment->is_rework || $lockedItem->factory_status !== 'Service');

                    if ($quantityToComplete > 0
                        && (! $canCompleteRegularProduction || blank($lockedItem->factory_drawing_image))) {
                        throw ValidationException::withMessages([
                            'quantity' => 'Produk tidak lagi dapat diselesaikan karena status atau gambar kerja tidak valid.',
                        ]);
                    }

                    if ($lockedAssignment->is_rework
                        && $quantityToComplete > (int) $lockedItem->quality_damaged_quantity) {
                        throw ValidationException::withMessages([
                            'quantity' => 'Jumlah selesai melebihi produk rusak yang tersedia untuk produksi ulang.',
                        ]);
                    }

                    if (! $lockedAssignment->is_rework
                        && $quantityToComplete > $lockedItem->factory_production_qty_remaining) {
                        throw ValidationException::withMessages([
                            'quantity' => 'Jumlah selesai melebihi sisa pekerjaan Factory.',
                        ]);
                    }

                    if ($quantityToComplete > 0) {
                        $lockedItem->increment('qty_completed', $quantityToComplete);
                        if ($lockedAssignment->is_rework) {
                            $lockedItem->decrement('quality_damaged_quantity', $quantityToComplete);
                        }
                    }

                    $lockedItem->refresh()->addInternalStockOutput($user);
                    $lockedItem->refresh()->synchronizeWorkflowStatuses();
                    $lockedOrder?->synchronizeProductionStatusFromItems();
                }
            }
        });

        return $employee->business_unit === User::UNIT_FACTORY
            ? redirect()
                ->route('orders.show', $pieceworkAssignment->orderItem->order_id)
                ->with('success', 'Jumlah penugasan borongan berhasil ditandai selesai.')
            : redirect()
                ->route('admin.attendance.index', ['work_type' => 'Borongan'])
                ->with('success', 'Jumlah tugas borongan berhasil ditandai selesai.');
    }

    private function ensureAttendanceAccess(User $user, PieceworkAssignment $pieceworkAssignment): Employee
    {
        $employee = $pieceworkAssignment->employee;
        abort_unless($employee, 404);
        abort_unless($user->canAccessMenu('attendance'), 403);
        abort_unless(
            in_array($employee->business_unit, [User::UNIT_BALI, User::UNIT_FACTORY], true)
                && $user->canAccessUnit($employee->business_unit),
            404,
        );

        return $employee;
    }

    private function normalizeUnitPrice(Request $request): void
    {
        $request->merge([
            'unit_price' => $this->normalizePriceValue($request->input('unit_price')),
        ]);
    }

    private function normalizeAssignmentUnitPrices(Request $request): void
    {
        $assignments = $request->input('assignments');

        if (! is_array($assignments)) {
            return;
        }

        foreach ($assignments as $index => $assignment) {
            if (is_array($assignment)) {
                $assignments[$index]['unit_price'] = $this->normalizePriceValue($assignment['unit_price'] ?? null);
            }
        }

        $request->merge(['assignments' => $assignments]);
    }

    private function normalizePriceValue(mixed $price): mixed
    {
        if (! is_string($price)) {
            return $price;
        }

        $price = trim($price);

        return preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $price) === 1
            ? str_replace(',', '', $price)
            : $price;
    }

    private function recordPieceworkPayment(PieceworkAssignment $assignment, int $quantity): void
    {
        if ($quantity < 1) {
            return;
        }

        $unitPrice = (float) $assignment->unit_price;
        PieceworkItem::create([
            'employee_id' => $assignment->employee_id,
            'order_item_id' => $assignment->order_item_id,
            'piecework_assignment_id' => $assignment->id,
            'product_name' => $assignment->product_name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => round($quantity * $unitPrice, 2),
            'target_deadline' => $assignment->target_deadline,
        ]);
    }
}
