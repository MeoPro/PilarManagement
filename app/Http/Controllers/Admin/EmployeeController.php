<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSchedule;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeOvertime;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PieceworkAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $validated = $request->validate([
            'employee_type' => ['nullable', 'in:Harian,Borongan'],
        ]);
        $canSelectUnit = ! $user->isOwner() && $user->canSelectBusinessUnit();
        $unitFilter = $canSelectUnit
            ? (string) $request->query('business_unit', '')
            : ($user->activeBusinessUnit() ?? '');
        $allowedUnits = $user->accessibleUnits();
        if (! in_array($unitFilter, ['', ...$allowedUnits], true)) {
            $unitFilter = '';
        }

        $employeeQuery = Employee::query()
            ->whereIn('business_unit', $allowedUnits)
            ->orderBy('full_name');

        $employeeQuery->when($unitFilter !== '', fn ($query) => $query->where('business_unit', $unitFilter));
        $employeeQuery->when(
            isset($validated['employee_type']),
            fn ($query) => $query->where('employee_type', $validated['employee_type']),
        );

        $employees = $employeeQuery->paginate(20)->withQueryString();
        $businessUnits = [
            User::UNIT_BALI => 'Pilar Bali',
            User::UNIT_FACTORY => 'Pilar Factory',
        ];

        $employeeTypeFilter = $validated['employee_type'] ?? '';

        return view('admin.employees.index', compact(
            'employees',
            'businessUnits',
            'unitFilter',
            'canSelectUnit',
            'employeeTypeFilter',
        ));
    }

    public function attendanceIndex(Request $request): View
    {
        $user = $request->user();
        $attendanceSchedule = AttendanceSchedule::current();
        $timezone = config('app.timezone');
        $timezoneLabel = $timezone === 'Asia/Jakarta' ? 'WIB' : 'WITA';
        $localNow = now($timezone);
        $today = $localNow->toDateString();
        $attendanceTransitions = collect([
            $attendanceSchedule->employeeWindow('morning')['start'],
            $attendanceSchedule->employeeWindow('morning')['end'],
            $attendanceSchedule->employeeWindow('afternoon')['start'],
            $attendanceSchedule->employeeWindow('afternoon')['end'],
        ])->map(function (string $time) use ($localNow): string {
            [$hour, $minute] = array_map('intval', explode(':', $time));

            return $localNow->copy()->setTime($hour, $minute)->toIso8601String();
        })->all();
        $validated = $request->validate([
            'work_type' => ['nullable', 'in:Harian,Borongan'],
            'employee_id' => ['nullable', 'integer'],
        ]);
        $workType = $validated['work_type'] ?? '';
        $allowedUnits = $user->accessibleUnits();
        $canSelectUnit = ! $user->isOwner() && $user->canSelectBusinessUnit();
        $unitFilter = $canSelectUnit
            ? (string) $request->query('business_unit', '')
            : ($user->activeBusinessUnit() ?? '');
        $activePieceworkAssignments = fn ($query) => $query
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->where(fn ($query) => $query
                ->whereNull('order_item_id')
                ->orWhereDoesntHave('orderItem.activeSubcontractorAssignments'));

        if (! in_array($unitFilter, ['', ...$allowedUnits], true)) {
            $unitFilter = '';
        }

        $employees = Employee::query()
            ->with(['attendances' => fn ($query) => $query
                ->whereDate('attendance_date', $today)
                ->with('pieceworkItems.assignment')])
            ->when($workType === 'Borongan', fn ($query) => $query->with([
                'pieceworkItems' => fn ($pieceworkItems) => $pieceworkItems
                    ->whereDate('created_at', $today)
                    ->orderBy('id'),
            ]))
            ->with(['overtimeEntries' => fn ($query) => $query->whereDate('overtime_date', $today)])
            ->with(['pieceworkAssignments' => fn ($query) => $activePieceworkAssignments($query)
                ->with('orderItem.order')
                ->withSum('pieceworkItems as produced_quantity', 'quantity')])
            ->when($workType === 'Harian', fn ($query) => $query->with([
                'finishingAssignments' => fn ($assignments) => $assignments
                    ->where(function ($query) use ($today): void {
                        $query->whereDate('started_at', $today)
                            ->orWhere(function ($query): void {
                                $query->whereNull('completed_at')
                                    ->whereNull('cancelled_at');
                            });
                    })
                    ->with('orderItem.order')
                    ->orderByDesc('started_at'),
            ]))
            ->where('status', 'Aktif')
            ->whereIn('business_unit', $allowedUnits)
            ->when($unitFilter !== '', fn ($query) => $query->where('business_unit', $unitFilter))
            ->when(
                isset($validated['employee_id']),
                fn ($query) => $query->whereKey($validated['employee_id']),
            )
            ->when($workType === '', fn ($query) => $query->whereRaw('1 = 0'))
            ->when($workType !== '', fn ($query) => $query->where('employee_type', $workType))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();
        $availableFactoryQuantity = "(CASE WHEN order_items.factory_status = 'Service' AND order_items.source != 'Stock' THEN order_items.qty - order_items.qty_completed - order_items.quality_short_quantity - order_items.quality_damaged_quantity WHEN COALESCE(order_items.quality_damaged_quantity, 0) > 0 THEN order_items.quality_damaged_quantity ELSE order_items.qty - order_items.qty_completed - order_items.quality_short_quantity END) > 0";
        $pieceworkOrders = $workType === 'Borongan'
            && in_array(User::UNIT_FACTORY, $allowedUnits, true)
            && $unitFilter !== User::UNIT_BALI
            ? Order::query()
                ->where('status', '!=', 'Cancel')
                ->whereHas('items', fn ($query) => $query
                    ->whereDoesntHave('activeSubcontractorAssignments')
                    ->where(function ($query): void {
                        $query->whereIn('factory_status', ['Siap Produksi', 'Produksi'])
                            ->orWhere(function ($serviceQuery): void {
                                $serviceQuery->where('factory_status', 'Service')
                                    ->where('source', '!=', 'Stock');
                            });
                    })
                    ->whereNotNull('factory_drawing_image')
                    ->whereRaw($availableFactoryQuantity))
                ->with(['items' => fn ($query) => $query
                    ->select('id', 'order_id', 'product', 'qty', 'qty_completed', 'quality_short_quantity', 'quality_damaged_quantity', 'factory_status', 'factory_drawing_image')
                    ->whereDoesntHave('activeSubcontractorAssignments')
                    ->where(function ($query): void {
                        $query->whereIn('factory_status', ['Siap Produksi', 'Produksi'])
                            ->orWhere(function ($serviceQuery): void {
                                $serviceQuery->where('factory_status', 'Service')
                                    ->where('source', '!=', 'Stock');
                            });
                    })
                    ->whereNotNull('factory_drawing_image')
                    ->whereRaw($availableFactoryQuantity)
                    ->withSum('activeSubcontractorAssignments as active_subcontracted_quantity', 'assigned_quantity')
                    ->orderBy('id')])
                ->orderByDesc('id')
                ->get(['id', 'order_id'])
            : collect();
        $finishingOrderItems = $workType === 'Harian'
            && in_array(User::UNIT_FACTORY, $allowedUnits, true)
            && $unitFilter !== User::UNIT_BALI
            ? OrderItem::query()
                ->whereHas('order', fn ($query) => $query->where('status', '!=', 'Cancel'))
                ->where(function ($query): void {
                    $query->where(function ($productionQuery): void {
                        $productionQuery->whereNotNull('factory_drawing_image')
                            ->where(function ($query): void {
                                $query->where('quality_damaged_quantity', 0)
                                    ->orWhere('factory_status', 'Finishing');
                            });
                    })->orWhere(function ($serviceQuery): void {
                        $serviceQuery->where('factory_status', 'Service')
                            ->where('quality_damaged_quantity', '>', 0);
                    });
                })
                ->where(function ($query): void {
                    $query->whereRaw(
                        'order_items.qty_completed > order_items.quality_good_quantity + order_items.quality_damaged_quantity + order_items.quality_short_quantity + order_items.packed_quantity',
                    )->orWhere('quality_damaged_quantity', '>', 0);
                })
                ->with([
                    'order:id,order_id,status',
                    'finishingAssignments',
                ])
                ->orderByDesc('id')
                ->get()
            : collect();
        $businessUnits = [
            User::UNIT_BALI => 'Pilar Bali',
            User::UNIT_FACTORY => 'Pilar Factory',
        ];

        return view('admin.attendance.index', compact(
            'employees',
            'businessUnits',
            'unitFilter',
            'canSelectUnit',
            'workType',
            'timezone',
            'timezoneLabel',
            'localNow',
            'attendanceSchedule',
            'attendanceTransitions',
            'pieceworkOrders',
            'finishingOrderItems',
        ));
    }

    public function create(): View
    {
        $user = request()->user();

        return view('admin.employees.form', [
            'employee' => new Employee([
                'business_unit' => $user->activeBusinessUnit() ?? User::UNIT_BALI,
                'employee_type' => 'Harian',
            ]),
            'isEditing' => false,
            'canSelectUnit' => ! $user->isOwner() && $user->canSelectBusinessUnit(),
            'localToday' => now($user->timezone())->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeDailyWage($request);
        $this->normalizeCashbond($request);
        $user = $request->user();
        $localToday = now($user->timezone())->toDateString();
        $dailyWageRules = $request->input('employee_type') === 'Harian'
            ? ['required', 'numeric', 'min:0']
            : ['prohibited'];
        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'address' => ['required', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'employee_type' => ['required', 'in:Harian,Borongan'],
            'employee_types' => ['prohibited'],
            'cashbond' => ['nullable', 'numeric', 'min:0'],
            'daily_wage' => $dailyWageRules,
            'started_at' => ['required', 'date', 'before_or_equal:'.$localToday],
            'status' => ['required', 'in:Aktif,Nonaktif'],
        ];

        if (! $user->isOwner() && $user->canSelectBusinessUnit()) {
            $rules['business_unit'] = ['required', Rule::in($user->accessibleUnits())];
        }

        $validated = $request->validate($rules);
        $photoPath = $request->file('photo')?->store('employees', 'local');

        Employee::create([
            'full_name' => $validated['full_name'],
            'photo_path' => $photoPath,
            'address' => $validated['address'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'employee_type' => $validated['employee_type'],
            'cashbond' => $validated['cashbond'] ?? 0,
            'daily_wage' => $validated['daily_wage'] ?? null,
            'business_unit' => $user->isOwner()
                ? ($user->activeBusinessUnit() ?? User::UNIT_BALI)
                : ($user->canSelectBusinessUnit()
                ? $validated['business_unit']
                : ($user->activeBusinessUnit() ?? User::UNIT_BALI)),
            'started_at' => $validated['started_at'],
            'status' => $validated['status'],
            'inactive_at' => $validated['status'] === 'Nonaktif' ? $localToday : null,
        ]);

        return redirect()
            ->route('admin.employees.index')
            ->with('success', 'Data karyawan berhasil ditambahkan.');
    }

    public function edit(Employee $employee): View
    {
        $this->ensureEmployeeAccess($employee);
        $user = request()->user();

        return view('admin.employees.form', [
            'employee' => $employee,
            'isEditing' => true,
            'canSelectUnit' => ! $user->isOwner() && $user->canSelectBusinessUnit(),
            'localToday' => now($user->timezone())->toDateString(),
        ]);
    }

    public function photo(Employee $employee): BinaryFileResponse
    {
        $this->ensureEmployeeAccess($employee);
        $disk = Storage::disk('local');

        abort_unless(
            $employee->photo_path !== null && $disk->exists($employee->photo_path),
            404,
        );

        return response()->file($disk->path($employee->photo_path), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function attendanceHistory(Employee $employee): View
    {
        $this->ensureEmployeeAccess($employee);
        $user = request()->user();
        $timezone = $user->timezone();
        $timezoneLabel = $timezone === 'Asia/Jakarta' ? 'WIB' : 'WITA';
        $attendances = $employee->attendances()
            ->with('pieceworkItems')
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate(30);
        $pieceworkAssignments = $employee->pieceworkAssignments()
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->with('orderItem.order')
            ->withSum('pieceworkItems as produced_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30, ['*'], 'assignment_page');
        $overtimeEntries = $employee->overtimeEntries()
            ->with('recordedBy:id,name')
            ->orderByDesc('overtime_date')
            ->orderByDesc('id')
            ->paginate(30, ['*'], 'overtime_page');
        $pieceworkHistoryItems = $employee->pieceworkItems()
            ->whereNull('employee_attendance_id')
            ->where(function ($query): void {
                $query->whereNull('piecework_assignment_id')
                    ->orWhereHas('assignment', fn ($assignment) => $assignment->whereNotNull('completed_at'));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30, ['*'], 'piecework_page');

        return view('admin.employees.attendance', compact(
            'employee',
            'attendances',
            'pieceworkAssignments',
            'overtimeEntries',
            'pieceworkHistoryItems',
            'timezone',
            'timezoneLabel',
        ));
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->ensureEmployeeAccess($employee);
        $this->normalizeDailyWage($request);
        $this->normalizeCashbond($request);
        $user = $request->user();
        $localToday = now($user->timezone())->toDateString();
        $dailyWageRules = $request->input('employee_type') === 'Harian'
            ? ['required', 'numeric', 'min:0']
            : ['prohibited'];
        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'address' => ['required', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'employee_type' => ['required', 'in:Harian,Borongan'],
            'employee_types' => ['prohibited'],
            'cashbond' => ['nullable', 'numeric', 'min:0'],
            'daily_wage' => $dailyWageRules,
            'started_at' => ['required', 'date', 'before_or_equal:'.$localToday],
            'status' => ['required', 'in:Aktif,Nonaktif'],
        ];

        if (! $user->isOwner() && $user->canSelectBusinessUnit()) {
            $rules['business_unit'] = ['required', Rule::in($user->accessibleUnits())];
        }

        $validated = $request->validate($rules);
        $previousPhotoPath = $employee->photo_path;

        if ($request->hasFile('photo')) {
            $validated['photo_path'] = $request->file('photo')->store('employees', 'local');
        }

        $employee->update([
            'full_name' => $validated['full_name'],
            'photo_path' => $validated['photo_path'] ?? $previousPhotoPath,
            'address' => $validated['address'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'employee_type' => $validated['employee_type'],
            'cashbond' => $validated['cashbond'] ?? $employee->cashbond ?? 0,
            'daily_wage' => $validated['daily_wage'] ?? null,
            'business_unit' => $user->isOwner()
                ? $employee->business_unit
                : ($user->canSelectBusinessUnit()
                ? $validated['business_unit']
                : $employee->business_unit),
            'started_at' => $validated['started_at'],
            'status' => $validated['status'],
            'inactive_at' => $validated['status'] === 'Nonaktif'
                ? ($employee->status === 'Aktif' ? $localToday : ($employee->inactive_at ?? $localToday))
                : null,
        ]);

        if (isset($validated['photo_path']) && $validated['photo_path'] !== $previousPhotoPath
            && $previousPhotoPath !== null) {
            Storage::disk('local')->delete($previousPhotoPath);
        }

        return redirect()
            ->route('admin.employees.index')
            ->with('success', 'Data karyawan berhasil diperbarui.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->ensureEmployeeAccess($employee);
        $photoPath = $employee->photo_path;
        $employee->delete();
        if ($photoPath !== null) {
            Storage::disk('local')->delete($photoPath);
        }

        return redirect()
            ->route('admin.employees.index')
            ->with('success', 'Data karyawan berhasil dihapus.');
    }

    public function attendance(Request $request, Employee $employee, string $session): RedirectResponse|JsonResponse
    {
        if (! in_array($session, ['morning', 'afternoon'], true)) {
            abort(404);
        }

        $this->ensureEmployeeAccess($employee);
        $this->normalizePieceworkItemPrices($request);
        $user = $request->user();
        $timezone = config('app.timezone');
        $timezoneLabel = $timezone === 'Asia/Jakarta' ? 'WIB' : 'WITA';
        $request->merge([
            'attendance_status' => $request->input('attendance_status', 'Hadir'),
        ]);
        $attendanceStatus = $request->input('attendance_status');
        $workType = $request->input('work_type');
        $isBaliBoronganAttendance = $workType === 'Borongan'
            && $employee->canRecordBaliBoronganAttendance();
        if ($workType === 'Borongan'
            && $employee->business_unit !== User::UNIT_FACTORY
            && ! $isBaliBoronganAttendance) {
            return $this->attendanceError(
                $request,
                'Absensi Borongan hanya tersedia untuk Pilar Factory atau karyawan Pilar Bali berjenis kerja Borongan.',
            );
        }

        $isFactoryPieceworkAttendance = $workType === 'Borongan'
            && $employee->business_unit === User::UNIT_FACTORY;
        $pieceworkRules = $isFactoryPieceworkAttendance || $isBaliBoronganAttendance
            ? ['nullable', 'array']
            : ($attendanceStatus === 'Hadir' && $workType === 'Borongan'
                ? ['required', 'array', 'min:1']
                : ['nullable', 'prohibited']);
        $factoryOrderIdRules = ['nullable', 'integer', 'exists:orders,id'];
        $factoryOrderItemIdRules = ['nullable', 'integer', 'exists:order_items,id'];

        $validated = $request->validate([
            'work_type' => ['required', 'in:Harian,Borongan'],
            'attendance_status' => ['required', 'in:Hadir,Izin,Alpa'],
            'status_note' => ['nullable', 'string', 'max:1000'],
            'piecework_items' => $pieceworkRules,
            'piecework_items.*' => ['required', 'array:order_id,order_item_id,assignment_id,product_name,quantity,unit_price,target_deadline'],
            'piecework_items.*.order_id' => $factoryOrderIdRules,
            'piecework_items.*.order_item_id' => $factoryOrderItemIdRules,
            'piecework_items.*.assignment_id' => ['nullable', 'integer', 'exists:piecework_assignments,id'],
            'piecework_items.*.product_name' => ['required', 'string', 'max:255'],
            'piecework_items.*.quantity' => ['required', 'integer', 'min:1'],
            'piecework_items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'piecework_items.*.target_deadline' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($employee->status !== 'Aktif') {
            return $this->attendanceError($request, 'Absensi hanya dapat dicatat untuk karyawan aktif.');
        }

        if ($employee->started_at->isFuture()) {
            return $this->attendanceError($request, 'Karyawan belum mulai bekerja.');
        }

        if (! $employee->supportsWorkType($validated['work_type'])) {
            return $this->attendanceError($request, 'Jenis absensi tidak sesuai dengan jenis kerja karyawan.');
        }

        if ($validated['attendance_status'] === 'Hadir'
            && $validated['work_type'] === 'Harian'
            && $employee->daily_wage === null) {
            return $this->attendanceError($request, 'Isi gaji harian pada data karyawan sebelum mencatat hadir sebagai Harian.');
        }

        $localNow = now($timezone);
        $attendanceDate = $localNow->toDateString();
        $now = $localNow->copy()->setTimezone(config('app.timezone'));
        $window = AttendanceSchedule::current()->employeeWindow($session);
        [$openHour, $openMinute] = array_map('intval', explode(':', $window['start']));
        [$cutoffHour, $cutoffMinute] = array_map('intval', explode(':', $window['end']));
        $opensAt = $localNow->copy()->setTime($openHour, $openMinute);
        $cutoff = $localNow->copy()->setTime($cutoffHour, $cutoffMinute);

        if ($localNow->lt($opensAt)) {
            return $this->attendanceError(
                $request,
                'Absensi '.($session === 'afternoon' ? 'sesi kedua' : 'sesi pertama').' belum dibuka.',
            );
        }

        if ($localNow->greaterThanOrEqualTo($cutoff)) {
            return $this->attendanceError($request, "Waktu absensi sesi tersebut sudah ditutup pada pukul {$window['end']} {$timezoneLabel}.");
        }

        $pieceworkItems = array_values($validated['piecework_items'] ?? []);
        $isPresent = $validated['attendance_status'] === 'Hadir';
        $recordedById = $request->user()->id;

        $inserted = DB::transaction(function () use (
            $employee,
            $session,
            $validated,
            $pieceworkItems,
            $now,
            $attendanceDate,
            $timezone,
            $isPresent,
            $recordedById,
        ): bool {
            $linkedItems = collect($pieceworkItems)
                ->filter(fn (array $item): bool => filled($item['order_item_id'] ?? null)
                    || filled($item['order_id'] ?? null)
                    || filled($item['assignment_id'] ?? null));
            $orderLinkedItems = $linkedItems->filter(fn (array $item): bool => filled($item['order_id'] ?? null)
                || filled($item['order_item_id'] ?? null));
            $linkedOrderIds = $orderLinkedItems->pluck('order_id')->filter()->unique()->sort()->values();
            $linkedItemIds = $orderLinkedItems->pluck('order_item_id')->filter()->unique()->sort()->values();

            if ($orderLinkedItems->contains(fn (array $item): bool => blank($item['order_id'] ?? null)
                || blank($item['order_item_id'] ?? null))) {
                throw ValidationException::withMessages([
                    'piecework_items' => 'Pilih SPK dan produk order secara lengkap.',
                ]);
            }

            if ($orderLinkedItems->isNotEmpty() && $employee->business_unit !== User::UNIT_FACTORY) {
                throw ValidationException::withMessages([
                    'piecework_items' => 'Produk order hanya dapat dicatat untuk karyawan Pilar Factory.',
                ]);
            }

            $ordersById = Order::query()
                ->whereKey($linkedOrderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status'])
                ->keyBy('id');
            $itemsById = OrderItem::query()
                ->whereKey($linkedItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $assignmentsById = PieceworkAssignment::query()
                ->whereKey($linkedItems->pluck('assignment_id')->filter()->unique()->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $newQuantitiesByItem = $orderLinkedItems
                ->groupBy('order_item_id')
                ->map(fn ($items) => $items->sum(fn (array $item): int => (int) $item['quantity']));
            $newQuantitiesByAssignment = $linkedItems
                ->groupBy('assignment_id')
                ->map(fn ($items) => $items->sum(fn (array $item): int => (int) $item['quantity']));

            foreach ($linkedItems as $index => $linkedItem) {
                $hasOrderLink = filled($linkedItem['order_id'] ?? null)
                    && filled($linkedItem['order_item_id'] ?? null);
                $order = $hasOrderLink ? $ordersById->get((int) $linkedItem['order_id']) : null;
                $orderItem = $hasOrderLink ? $itemsById->get((int) $linkedItem['order_item_id']) : null;
                $assignment = filled($linkedItem['assignment_id'] ?? null)
                    ? $assignmentsById->get((int) $linkedItem['assignment_id'])
                    : null;

                if ($hasOrderLink && (! $order || ! $orderItem
                    || (int) $orderItem->order_id !== (int) $order->id)) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.order_item_id" => 'Produk tidak sesuai dengan SPK yang dipilih.',
                    ]);
                }

                if (filled($linkedItem['assignment_id'] ?? null)
                    && (! $assignment
                        || (int) $assignment->employee_id !== (int) $employee->id
                        || $assignment->completed_at
                        || ($assignment->order_item_id !== null
                            && (! $orderItem || (int) $assignment->order_item_id !== (int) $orderItem->id))
                        || ($assignment->order_item_id === null
                            && ($hasOrderLink || $employee->business_unit !== User::UNIT_BALI)))) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.assignment_id" => 'Penugasan tidak berlaku untuk karyawan atau produk ini.',
                    ]);
                }

                if ($hasOrderLink && ($order->status === 'Cancel'
                    || ! in_array($orderItem->factory_status, ['Siap Produksi', 'Produksi'], true)
                    || blank($orderItem->factory_drawing_image))) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.order_item_id" => 'Produk hanya dapat dikerjakan setelah berstatus Siap Produksi dan memiliki gambar kerja Pilar Factory.',
                    ]);
                }

                if ($assignment !== null
                    && $assignment->is_rework !== ((int) $orderItem->quality_damaged_quantity > 0)) {
                    throw ValidationException::withMessages([
                        "piecework_items.{$index}.assignment_id" => 'Jenis penugasan tidak sesuai dengan status produksi ulang produk.',
                    ]);
                }
            }

            foreach ($newQuantitiesByAssignment as $assignmentId => $newQuantity) {
                $assignment = $assignmentsById->get((int) $assignmentId);
                if (! $assignment) {
                    continue;
                }
                $alreadyProduced = (int) $assignment->pieceworkItems()->sum('quantity');

                if ($alreadyProduced + (int) $newQuantity > $assignment->target_quantity) {
                    throw ValidationException::withMessages([
                        'piecework_items' => 'Jumlah melebihi target penugasan borongan untuk '.$assignment->product_name.'.',
                    ]);
                }
            }

            foreach ($newQuantitiesByItem as $orderItemId => $newQuantity) {
                $orderItem = $itemsById->get((int) $orderItemId);
                if ((int) $orderItem->quality_damaged_quantity > 0) {
                    if ((int) $newQuantity > (int) $orderItem->quality_damaged_quantity) {
                        throw ValidationException::withMessages([
                            'piecework_items' => "Jumlah produksi ulang melebihi produk rusak yang tersedia untuk {$orderItem->product}.",
                        ]);
                    }

                    continue;
                }

                $existingPieceworkQuantity = (int) $orderItem->pieceworkItems()->sum('quantity');
                $alreadyCountedQuantity = max($existingPieceworkQuantity, (int) $orderItem->qty_completed);

                if ($alreadyCountedQuantity + (int) $newQuantity
                    > max($orderItem->factory_quantity - (int) $orderItem->quality_short_quantity, 0)) {
                    throw ValidationException::withMessages([
                        'piecework_items' => "Jumlah borongan melebihi sisa produk Factory untuk {$orderItem->product}.",
                    ]);
                }
            }

            $pieceworkItems = array_map(function (array $item) use ($assignmentsById): array {
                $assignment = filled($item['assignment_id'] ?? null)
                    ? $assignmentsById->get((int) $item['assignment_id'])
                    : null;

                if ($assignment?->unit_price !== null) {
                    $item['unit_price'] = (float) $assignment->unit_price;
                }

                return $item;
            }, $pieceworkItems);
            $pieceworkAmount = round(array_sum(array_map(
                fn (array $item): float => (int) $item['quantity'] * (float) $item['unit_price'],
                $pieceworkItems,
            )), 2);

            $inserted = DB::table('employee_attendances')->insertOrIgnore([
                'employee_id' => $employee->id,
                'attendance_date' => $attendanceDate,
                'session' => $session,
                'work_type' => $validated['work_type'],
                'attendance_timezone' => $timezone,
                'attendance_status' => $validated['attendance_status'],
                'status_note' => $validated['status_note'] ?? null,
                'recorded_by_id' => $recordedById,
                'daily_pay_amount' => $isPresent && $validated['work_type'] === 'Harian'
                    ? round((float) $employee->daily_wage / 2, 2)
                    : 0,
                'piecework_amount' => $isPresent && $validated['work_type'] === 'Borongan' ? $pieceworkAmount : 0,
                'attended_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! $inserted) {
                return false;
            }

            if ($isPresent && $validated['work_type'] === 'Borongan') {
                $attendance = $employee->attendances()
                    ->whereDate('attendance_date', $attendanceDate)
                    ->where('session', $session)
                    ->firstOrFail();

                $attendance->pieceworkItems()->createMany(array_map(
                    function (array $item) use ($assignmentsById, $employee): array {
                        $assignment = filled($item['assignment_id'] ?? null)
                            ? $assignmentsById->get((int) $item['assignment_id'])
                            : null;

                        return [
                            'employee_id' => $employee->id,
                            'product_name' => $assignment?->product_name ?? $item['product_name'],
                            'order_item_id' => filled($item['order_item_id'] ?? null) ? $item['order_item_id'] : null,
                            'piecework_assignment_id' => $assignment?->id,
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'line_total' => round((int) $item['quantity'] * (float) $item['unit_price'], 2),
                            'target_deadline' => $assignment?->target_deadline ?? $item['target_deadline'],
                        ];
                    },
                    $pieceworkItems,
                ));

                foreach ($newQuantitiesByItem as $orderItemId => $newQuantity) {
                    $orderItem = $itemsById->get((int) $orderItemId);
                    $isRework = (int) $orderItem->quality_damaged_quantity > 0;
                    if ($orderItem->factory_status === 'Siap Produksi') {
                        $orderItem->update(['factory_status' => 'Produksi']);
                    }
                    $orderItem->increment('qty_completed', (int) $newQuantity);
                    if ($isRework) {
                        $orderItem->decrement('quality_damaged_quantity', (int) $newQuantity);
                    }
                    $orderItem->refresh()->synchronizeWorkflowStatuses();

                    $ordersById->get((int) $orderItem->order_id)?->synchronizeProductionStatusFromItems();
                }
            }

            return true;
        });

        if (! $inserted) {
            return $this->attendanceError($request, 'Absensi sesi tersebut sudah dicatat hari ini.');
        }

        $message = 'Status '.strtolower($validated['attendance_status']).' sesi '.($session === 'morning' ? '1' : '2').' berhasil dicatat.';

        if ($request->expectsJson()) {
            $attendance = $employee->attendances()
                ->whereDate('attendance_date', $attendanceDate)
                ->where('session', $session)
                ->with('employee', 'pieceworkItems.assignment')
                ->firstOrFail();

            return response()->json([
                'message' => $message,
                'session' => $session,
                'employee_id' => $employee->id,
                'attendance_cell' => view('admin.attendance.partials.session-status', [
                    'attendance' => $attendance,
                    'employee' => $employee,
                    'timezone' => $timezone,
                ])->render(),
            ]);
        }

        return back()->with('success', $message);
    }

    private function attendanceError(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }

    public function cancelAttendance(
        Request $request,
        Employee $employee,
        EmployeeAttendance $attendance,
    ): RedirectResponse {
        $this->ensureEmployeeAccess($employee);
        abort_unless($attendance->employee_id === $employee->id, 404);
        abort_unless($request->user()->isOwner() || $request->user()->canAccessMenu('attendance'), 403);

        if ($attendance->attendance_date->toDateString() !== now(config('app.timezone'))->toDateString()
            || $attendance->work_type !== 'Harian'
            || $attendance->attendance_status !== 'Hadir') {
            return back()->with('error', 'Hanya absensi Harian berstatus Hadir yang dapat dibatalkan.');
        }

        if ($attendance->corrected_at !== null) {
            return back()->with('error', 'Absensi yang sudah dikoreksi tidak dapat dibatalkan.');
        }

        if ($attendance->pieceworkItems()->exists()) {
            return back()->with('error', 'Batalkan atau pindahkan hasil Borongan terkait sebelum menghapus absensi.');
        }

        $attendance->delete();

        return back()->with('success', 'Absensi Harian sesi '.($attendance->session === 'morning' ? '1' : '2').' berhasil dibatalkan.');
    }

    public function correctAttendance(
        Request $request,
        Employee $employee,
        EmployeeAttendance $attendance,
    ): RedirectResponse {
        $this->ensureEmployeeAccess($employee);
        abort_unless($attendance->employee_id === $employee->id, 404);
        abort_unless($request->user()->isOwner() || $request->user()->canAccessMenu('attendance'), 403);

        $validated = $request->validate([
            'attendance_status' => ['required', 'in:Hadir,Izin,Alpa'],
            'correction_reason' => ['required', 'string', 'max:1000'],
            'status_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $wasPresent = $attendance->attendance_status === 'Hadir';
        $isPresent = $validated['attendance_status'] === 'Hadir';
        $dailyPayAmount = 0;
        $pieceworkAmount = 0;

        if ($isPresent) {
            if ($attendance->work_type === 'Harian') {
                if ($employee->daily_wage === null) {
                    return back()->with('error', 'Isi gaji harian karyawan sebelum mengoreksi absensi menjadi Hadir.');
                }

                $dailyPayAmount = round((float) $employee->daily_wage / 2, 2);
            } else {
                $pieceworkAmount = (float) $attendance->pieceworkItems()->sum('line_total');
            }
        }

        $attendance->update([
            'attendance_status' => $validated['attendance_status'],
            'status_note' => $validated['status_note'] ?? null,
            'daily_pay_amount' => $dailyPayAmount,
            'piecework_amount' => $pieceworkAmount,
            'corrected_by_id' => $request->user()->id,
            'corrected_at' => now(),
            'correction_reason' => $validated['correction_reason'],
        ]);

        return back()->with(
            'success',
            'Status absensi berhasil dikoreksi'.($wasPresent === $isPresent ? ' dan alasannya dicatat.' : '.'),
        );
    }

    public function overtime(Request $request, Employee $employee): RedirectResponse
    {
        $this->ensureEmployeeAccess($employee);
        $user = $request->user();
        $timezone = $user->timezone();
        $today = now($timezone)->toDateString();

        if ($employee->status !== 'Aktif' || ! $employee->supportsWorkType('Harian')) {
            return back()->with('error', 'Lembur hanya dapat dicatat untuk karyawan aktif berjenis kerja Harian.');
        }

        $validated = $request->validate([
            'overtime_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$employee->started_at->toDateString(),
                'before_or_equal:'.$today,
            ],
            'overtime_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);

        DB::transaction(function () use ($employee, $validated, $user): void {
            $employee->update(['overtime_hourly_rate' => $validated['hourly_rate']]);

            EmployeeOvertime::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'overtime_date' => $validated['overtime_date'],
                ],
                [
                    'overtime_hours' => $validated['overtime_hours'],
                    'hourly_rate' => $validated['hourly_rate'],
                    'recorded_by_id' => $user->id,
                ],
            );
        });

        return back()->with('success', 'Jam dan tarif lembur berhasil disimpan.');
    }

    private function normalizeDailyWage(Request $request): void
    {
        $this->normalizeCurrencyInput($request, 'daily_wage');
    }

    private function normalizeCashbond(Request $request): void
    {
        $this->normalizeCurrencyInput($request, 'cashbond');
    }

    private function normalizePieceworkItemPrices(Request $request): void
    {
        $items = $request->input('piecework_items');
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (is_array($item) && is_string($item['unit_price'] ?? null)) {
                $price = $item['unit_price'];
                if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', trim($price)) === 1) {
                    $items[$index]['unit_price'] = str_replace(',', '', trim($price));
                }
            }
        }

        $request->merge(['piecework_items' => $items]);
    }

    private function normalizeCurrencyInput(Request $request, string $key): void
    {
        $amount = $request->input($key);

        if (! is_string($amount)) {
            return;
        }

        $amount = trim($amount);

        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $amount) === 1) {
            $request->merge([$key => str_replace(',', '', $amount)]);
        }
    }

    private function ensureEmployeeAccess(Employee $employee): void
    {
        abort_unless(auth()->user()->canAccessUnit($employee->business_unit), 404);
        abort_if(
            auth()->user()->isOwner()
                && $employee->business_unit !== auth()->user()->activeBusinessUnit(),
            404,
        );
    }
}
