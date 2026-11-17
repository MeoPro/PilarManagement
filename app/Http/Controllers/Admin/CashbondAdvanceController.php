<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashbondAdvance;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeOvertime;
use App\Models\PieceworkItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CashbondAdvanceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $localToday = now($user->timezone())->startOfDay();
        $validated = $request->validate([
            'business_unit' => ['nullable', 'in:bali,factory'],
        ]);
        $allowedUnits = $user->accessibleUnits();
        $canSelectUnit = ! $user->isOwner() && $user->canSelectBusinessUnit();
        $unitFilter = $canSelectUnit
            ? ($validated['business_unit'] ?? '')
            : ($user->activeBusinessUnit() ?? '');

        if (! in_array($unitFilter, ['', ...$allowedUnits], true)) {
            $unitFilter = '';
        }

        $employees = Employee::query()
            ->whereIn('business_unit', $allowedUnits)
            ->where('status', 'Aktif')
            ->whereDate('started_at', '<=', $localToday->toDateString())
            ->when($unitFilter !== '', fn ($query) => $query->where('business_unit', $unitFilter))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'business_unit', 'cashbond']);
        $advances = CashbondAdvance::query()
            ->with(['employee:id,full_name,business_unit', 'recordedBy:id,name'])
            ->whereHas('employee', fn ($query) => $query
                ->whereIn('business_unit', $allowedUnits)
                ->when($unitFilter !== '', fn ($employeeQuery) => $employeeQuery->where('business_unit', $unitFilter)))
            ->orderByDesc('advance_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
        $businessUnits = [
            User::UNIT_BALI => 'Pilar Bali',
            User::UNIT_FACTORY => 'Pilar Factory',
        ];

        return view('admin.cashbond.index', compact(
            'employees',
            'advances',
            'businessUnits',
            'unitFilter',
            'canSelectUnit',
            'localToday',
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeAmount($request);
        $user = $request->user();
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'advance_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'business_unit' => ['nullable', 'in:bali,factory'],
        ]);
        $allowedUnits = $user->accessibleUnits();
        $employee = Employee::query()
            ->whereKey($validated['employee_id'])
            ->whereIn('business_unit', $allowedUnits)
            ->when(
                ! $user->isOwner() && $user->canSelectBusinessUnit() && ($validated['business_unit'] ?? '') !== '',
                fn ($query) => $query->where('business_unit', $validated['business_unit']),
            )
            ->firstOrFail();

        abort_if(
            $user->isOwner() && $employee->business_unit !== $user->activeBusinessUnit(),
            404,
        );

        $timezone = User::timezoneForUnit($employee->business_unit);
        $advanceDate = Carbon::createFromFormat('Y-m-d', $validated['advance_date'], $timezone)->startOfDay();
        $this->ensureEmployeeCanReceiveAdvance($employee, $advanceDate);
        if ($advanceDate->gt(now($timezone)->startOfDay())) {
            throw ValidationException::withMessages([
                'advance_date' => 'Tanggal Cashbond tidak boleh di masa depan.',
            ]);
        }
        $amount = round((float) $validated['amount'], 2);

        $advance = DB::transaction(function () use ($employee, $advanceDate, $amount, $user): CashbondAdvance {
            $lockedEmployee = Employee::query()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($user->canAccessUnit($lockedEmployee->business_unit), 404);
            abort_if(
                $user->isOwner() && $lockedEmployee->business_unit !== $user->activeBusinessUnit(),
                404,
            );
            $this->ensureEmployeeCanReceiveAdvance($lockedEmployee, $advanceDate);

            $salaryEarned = $this->salaryEarnedThroughDate($lockedEmployee, $advanceDate);
            $maximumDebt = round($salaryEarned * 2, 2);
            $currentDebt = round((float) $lockedEmployee->cashbond, 2);
            $availableAdvance = max(round($maximumDebt - $currentDebt, 2), 0);

            if ($amount > $availableAdvance) {
                throw ValidationException::withMessages([
                    'amount' => 'Jumlah Cashbond melebihi batas tambahan saat ini Rp '
                        .number_format($availableAdvance, 0, ',', '.')
                        .'. Batas total adalah 2 kali gaji terkumpul sampai tanggal tersebut.',
                ]);
            }

            $balanceAfter = round($currentDebt + $amount, 2);
            $advance = CashbondAdvance::create([
                'employee_id' => $lockedEmployee->id,
                'recorded_by_id' => $user->id,
                'advance_date' => $advanceDate->toDateString(),
                'salary_earned' => $salaryEarned,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
            ]);
            $lockedEmployee->update(['cashbond' => $balanceAfter]);

            return $advance;
        });

        return redirect()
            ->route('admin.cashbond.index', $request->only('business_unit'))
            ->with(
                'success',
                'Cashbond Rp '.number_format((float) $advance->amount, 0, ',', '.')
                    .' untuk '.$employee->full_name.' berhasil dicatat.',
            );
    }

    private function ensureEmployeeCanReceiveAdvance(Employee $employee, Carbon $advanceDate): void
    {
        if ($employee->status !== 'Aktif'
            || $employee->started_at === null
            || $employee->started_at->gt($advanceDate)
            || ($employee->inactive_at !== null && $employee->inactive_at->lt($advanceDate))) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pilih karyawan yang sudah mulai bekerja dan masih aktif pada tanggal Cashbond.',
            ]);
        }
    }

    private function salaryEarnedThroughDate(Employee $employee, Carbon $advanceDate): float
    {
        $periodStart = $advanceDate->copy()->startOfMonth();
        if ($employee->started_at->gt($periodStart)) {
            $periodStart = Carbon::createFromFormat(
                'Y-m-d',
                $employee->started_at->toDateString(),
                $advanceDate->getTimezone(),
            )->startOfDay();
        }
        $periodStartDate = $periodStart->toDateString();
        $advanceDateString = $advanceDate->toDateString();
        $periodStartTimestamp = $periodStart->copy()->setTimezone(config('app.timezone'));
        $periodEndTimestamp = $advanceDate->copy()
            ->addDay()
            ->startOfDay()
            ->setTimezone(config('app.timezone'));
        $attendanceTotals = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_status', 'Hadir')
            ->whereBetween('attendance_date', [$periodStartDate, $advanceDateString])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN work_type = ? THEN daily_pay_amount ELSE 0 END), 0) as daily_pay_total',
                ['Harian'],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN work_type = ? THEN piecework_amount ELSE 0 END), 0) as piecework_pay_total',
                ['Borongan'],
            )
            ->first();
        $overtimePayTotal = (float) EmployeeOvertime::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('overtime_date', [$periodStartDate, $advanceDateString])
            ->sum(DB::raw('overtime_hours * hourly_rate'));
        $standalonePieceworkTotal = (float) PieceworkItem::query()
            ->where('employee_id', $employee->id)
            ->whereNull('employee_attendance_id')
            ->where('created_at', '>=', $periodStartTimestamp)
            ->where('created_at', '<', $periodEndTimestamp)
            ->sum('line_total');

        return round(
            (float) $attendanceTotals->daily_pay_total
                + (float) $attendanceTotals->piecework_pay_total
                + $overtimePayTotal
                + $standalonePieceworkTotal,
            2,
        );
    }

    private function normalizeAmount(Request $request): void
    {
        $amount = $request->input('amount');
        if (! is_string($amount)) {
            return;
        }

        $amount = trim($amount);
        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $amount) === 1) {
            $request->merge(['amount' => str_replace(',', '', $amount)]);
        }
    }
}
