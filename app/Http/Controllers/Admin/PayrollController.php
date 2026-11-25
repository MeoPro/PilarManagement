<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeOvertime;
use App\Models\PayrollPayment;
use App\Models\PieceworkItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $localToday = now($user->timezone())->startOfDay();
        $validated = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'business_unit' => ['nullable', 'in:bali,factory'],
            'salary_type' => ['nullable', 'in:Harian,Borongan'],
        ]);
        $period = $validated['period'] ?? $localToday->format('Y-m');
        $salaryType = $validated['salary_type'] ?? '';
        abort_if($period > $localToday->format('Y-m'), 422, 'Bulan yang dipilih belum dimulai.');

        $periodStart = Carbon::createFromFormat('Y-m-d', $period.'-01', $user->timezone())->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();
        if ($periodEnd->gt($localToday)) {
            $periodEnd = $localToday->copy();
        }
        $periodStartTimestamp = $periodStart->copy()->setTimezone(config('app.timezone'));
        $periodEndTimestamp = $periodEnd->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone'));

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
            ->when($unitFilter !== '', fn ($query) => $query->where('business_unit', $unitFilter))
            ->whereDate('started_at', '<=', $periodEnd->toDateString())
            ->where(function ($query) use ($periodStart) {
                $query->whereNull('inactive_at')
                    ->orWhereDate('inactive_at', '>=', $periodStart->toDateString());
            })
            ->with(['payrollPayments' => fn ($query) => $query
                ->where('period', $period)
                ->orderByDesc('paid_at')])
            ->orderBy('full_name')
            ->paginate(30)
            ->withQueryString();

        $employeeIds = $employees->getCollection()->modelKeys();
        $attendanceTotals = EmployeeAttendance::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('attendance_status', 'Hadir')
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->select('employee_id')
            ->selectRaw('SUM(CASE WHEN work_type = ? THEN 1 ELSE 0 END) as daily_sessions', ['Harian'])
            ->selectRaw(
                'SUM(CASE WHEN work_type = ? THEN daily_pay_amount ELSE 0 END) as daily_pay_total',
                ['Harian'],
            )
            ->selectRaw(
                'SUM(CASE WHEN work_type = ? THEN piecework_amount ELSE 0 END) as piecework_pay_total',
                ['Borongan'],
            )
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');
        $overtimeTotals = EmployeeOvertime::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('overtime_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->select('employee_id')
            ->selectRaw('SUM(overtime_hours * hourly_rate) as overtime_pay_total')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');
        $standalonePieceworkTotals = PieceworkItem::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereNull('employee_attendance_id')
            ->where('created_at', '>=', $periodStartTimestamp)
            ->where('created_at', '<', $periodEndTimestamp)
            ->select('employee_id')
            ->selectRaw('SUM(line_total) as piecework_pay_total')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $summaries = [];
        foreach ($employees as $employee) {
            $totals = $attendanceTotals->get($employee->id);
            $dailySessions = (int) ($totals?->daily_sessions ?? 0);
            $dailyPayTotal = round((float) ($totals?->daily_pay_total ?? 0), 2);
            $overtimePayTotal = round((float) ($overtimeTotals->get($employee->id)?->overtime_pay_total ?? 0), 2);
            $pieceworkPayTotal = round(
                (float) ($totals?->piecework_pay_total ?? 0)
                    + (float) ($standalonePieceworkTotals->get($employee->id)?->piecework_pay_total ?? 0),
                2,
            );
            $salaryTotal = round($dailyPayTotal + $overtimePayTotal + $pieceworkPayTotal, 2);
            $dailySalaryPaid = round((float) $employee->payrollPayments->sum('daily_salary_paid'), 2);
            $pieceworkSalaryPaid = round((float) $employee->payrollPayments->sum('piecework_salary_paid'), 2);
            $dailySalaryDue = max(round($dailyPayTotal + $overtimePayTotal - $dailySalaryPaid, 2), 0);
            $pieceworkSalaryDue = max(round($pieceworkPayTotal - $pieceworkSalaryPaid, 2), 0);
            $salaryPaid = round($dailySalaryPaid + $pieceworkSalaryPaid, 2);

            $summaries[$employee->id] = [
                'daily_sessions' => $dailySessions,
                'daily_pay_total' => $dailyPayTotal,
                'overtime_pay_total' => $overtimePayTotal,
                'piecework_pay_total' => $pieceworkPayTotal,
                'salary_total' => $salaryTotal,
                'salary_paid' => $salaryPaid,
                'daily_salary_paid' => $dailySalaryPaid,
                'piecework_salary_paid' => $pieceworkSalaryPaid,
                'daily_salary_due' => $dailySalaryDue,
                'piecework_salary_due' => $pieceworkSalaryDue,
                'salary_due' => $salaryType === 'Harian'
                    ? $dailySalaryDue
                    : ($salaryType === 'Borongan' ? $pieceworkSalaryDue : max(round($salaryTotal - $salaryPaid, 2), 0)),
                'cashbond_paid' => round((float) $employee->payrollPayments->sum('cashbond_paid'), 2),
            ];
        }

        $businessUnits = [
            User::UNIT_BALI => 'Pilar Bali',
            User::UNIT_FACTORY => 'Pilar Factory',
        ];
        $payrollEmployeeIds = Employee::query()
            ->whereIn('business_unit', $allowedUnits)
            ->when($unitFilter !== '', fn ($query) => $query->where('business_unit', $unitFilter))
            ->whereDate('started_at', '<=', $periodEnd->toDateString())
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('inactive_at')
                    ->orWhereDate('inactive_at', '>=', $periodStart->toDateString());
            })
            ->pluck('id');
        $periodAttendance = EmployeeAttendance::query()
            ->whereIn('employee_id', $payrollEmployeeIds)
            ->where('attendance_status', 'Hadir')
            ->whereBetween('attendance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN work_type = ? THEN daily_pay_amount ELSE 0 END), 0) as daily_pay_total',
                ['Harian'],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN work_type = ? THEN piecework_amount ELSE 0 END), 0) as piecework_pay_total',
                ['Borongan'],
            )
            ->first();
        $periodOvertimeTotal = (float) EmployeeOvertime::query()
            ->whereIn('employee_id', $payrollEmployeeIds)
            ->whereBetween('overtime_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->sum(DB::raw('overtime_hours * hourly_rate'));
        $standalonePieceworkTotal = (float) PieceworkItem::query()
            ->whereIn('employee_id', $payrollEmployeeIds)
            ->whereNull('employee_attendance_id')
            ->where('created_at', '>=', $periodStartTimestamp)
            ->where('created_at', '<', $periodEndTimestamp)
            ->sum('line_total');
        $paidDailySalary = (float) PayrollPayment::query()
            ->whereIn('employee_id', $payrollEmployeeIds)
            ->where('period', $period)
            ->sum('daily_salary_paid');
        $paidPieceworkSalary = (float) PayrollPayment::query()
            ->whereIn('employee_id', $payrollEmployeeIds)
            ->where('period', $period)
            ->sum('piecework_salary_paid');
        $unpaidSalaryTotal = max(
            round((float) $periodAttendance->daily_pay_total
                + $periodOvertimeTotal
                + (float) $periodAttendance->piecework_pay_total
                + $standalonePieceworkTotal
                - $paidDailySalary
                - $paidPieceworkSalary, 2),
            0,
        );
        $debtEmployees = Employee::query()
            ->whereIn('business_unit', $allowedUnits)
            ->when($unitFilter !== '', fn ($query) => $query->where('business_unit', $unitFilter))
            ->where('cashbond', '>', 0);
        $cashbondEmployeeCount = (clone $debtEmployees)->count();
        $cashbondTotal = round((float) $debtEmployees->sum('cashbond'), 2);
        $activeEmployeeCount = Employee::query()
            ->whereIn('business_unit', $allowedUnits)
            ->when($unitFilter !== '', fn ($query) => $query->where('business_unit', $unitFilter))
            ->where('status', 'Aktif')
            ->count();

        return view('admin.payroll.index', compact(
            'employees',
            'summaries',
            'period',
            'periodStart',
            'periodEnd',
            'salaryType',
            'businessUnits',
            'unitFilter',
            'canSelectUnit',
            'unpaidSalaryTotal',
            'cashbondEmployeeCount',
            'cashbondTotal',
            'activeEmployeeCount',
            'localToday',
        ));
    }

    public function pay(Request $request, Employee $employee): RedirectResponse
    {
        $this->normalizeCashbondPayment($request);
        $this->normalizeSalaryPayment($request);
        $localToday = now($request->user()->timezone())->startOfDay();
        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'salary_type' => ['nullable', 'in:Harian,Borongan'],
            'salary_payment' => ['exclude_if:pay_all,1', 'sometimes', 'required', 'numeric', 'min:0'],
            'pay_all' => ['nullable', 'boolean'],
            'cashbond_payment' => ['nullable', 'numeric', 'min:0'],
            'pay_cashbond_all' => ['nullable', 'boolean'],
        ]);
        $period = $validated['period'];
        $salaryType = $validated['salary_type'] ?? '';
        abort_if($period > $localToday->format('Y-m'), 422, 'Bulan yang dipilih belum dimulai.');
        abort_unless($request->user()->canAccessUnit($employee->business_unit), 404);
        abort_if(
            $request->user()->isOwner()
                && $employee->business_unit !== $request->user()->activeBusinessUnit(),
            404,
        );

        $payment = DB::transaction(function () use ($employee, $period, $salaryType, $request, $validated): array {
            $employee = Employee::query()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($request->user()->canAccessUnit($employee->business_unit), 404);
            abort_if(
                $request->user()->isOwner()
                    && $employee->business_unit !== $request->user()->activeBusinessUnit(),
                404,
            );

            $localToday = now($request->user()->timezone())->startOfDay();
            $periodStart = Carbon::createFromFormat('Y-m-d', $period.'-01', $request->user()->timezone())->startOfDay();
            $periodEnd = $periodStart->copy()->endOfMonth();
            if ($periodEnd->gt($localToday)) {
                $periodEnd = $localToday->copy();
            }
            $periodStartTimestamp = $periodStart->copy()->setTimezone(config('app.timezone'));
            $periodEndTimestamp = $periodEnd->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone'));

            $attendanceTotals = EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_status', 'Hadir')
                ->whereDate('attendance_date', '>=', $periodStart->toDateString())
                ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
                ->selectRaw('SUM(CASE WHEN work_type = ? THEN 1 ELSE 0 END) as daily_sessions', ['Harian'])
                ->selectRaw(
                    'SUM(CASE WHEN work_type = ? THEN daily_pay_amount ELSE 0 END) as daily_pay_total',
                    ['Harian'],
                )
                ->selectRaw(
                    'SUM(CASE WHEN work_type = ? THEN piecework_amount ELSE 0 END) as piecework_pay_total',
                    ['Borongan'],
                )
                ->first();
            $overtimePayTotal = round((float) EmployeeOvertime::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('overtime_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->sum(DB::raw('overtime_hours * hourly_rate')), 2);
            $standalonePieceworkTotal = (float) PieceworkItem::query()
                ->where('employee_id', $employee->id)
                ->whereNull('employee_attendance_id')
                ->where('created_at', '>=', $periodStartTimestamp)
                ->where('created_at', '<', $periodEndTimestamp)
                ->sum('line_total');

            $dailySessions = (int) ($attendanceTotals?->daily_sessions ?? 0);
            $dailyPayTotal = round((float) ($attendanceTotals?->daily_pay_total ?? 0), 2);
            $pieceworkPayTotal = round(
                (float) ($attendanceTotals?->piecework_pay_total ?? 0)
                    + $standalonePieceworkTotal,
                2,
            );
            $dailySalaryPaidBefore = (float) PayrollPayment::query()
                ->where('employee_id', $employee->id)
                ->where('period', $period)
                ->sum('daily_salary_paid');
            $pieceworkSalaryPaidBefore = (float) PayrollPayment::query()
                ->where('employee_id', $employee->id)
                ->where('period', $period)
                ->sum('piecework_salary_paid');
            $dailySalaryDue = max(round($dailyPayTotal + $overtimePayTotal - $dailySalaryPaidBefore, 2), 0);
            $pieceworkSalaryDue = max(round($pieceworkPayTotal - $pieceworkSalaryPaidBefore, 2), 0);
            $salaryDue = $salaryType === 'Harian'
                ? $dailySalaryDue
                : ($salaryType === 'Borongan' ? $pieceworkSalaryDue : round($dailySalaryDue + $pieceworkSalaryDue, 2));
            $salaryPaid = (bool) ($validated['pay_all'] ?? false)
                ? $salaryDue
                : round((float) ($validated['salary_payment'] ?? $salaryDue), 2);

            if ($salaryPaid > $salaryDue) {
                throw ValidationException::withMessages([
                    'salary_payment' => 'Jumlah gaji tidak boleh melebihi sisa gaji periode ini.',
                ]);
            }

            $dailySalaryPaid = $salaryType === 'Borongan'
                ? 0
                : min($salaryPaid, $dailySalaryDue);
            $pieceworkSalaryPaid = $salaryType === 'Harian'
                ? 0
                : min(max($salaryPaid - $dailySalaryPaid, 0), $pieceworkSalaryDue);
            $salaryPaid = round($dailySalaryPaid + $pieceworkSalaryPaid, 2);
            $cashbondPaymentRequested = (bool) ($validated['pay_cashbond_all'] ?? false)
                ? (float) $employee->cashbond
                : round((float) ($validated['cashbond_payment'] ?? 0), 2);
            $cashbondBalance = (float) $employee->cashbond;

            if ($salaryPaid > 0 && $cashbondPaymentRequested > 0) {
                throw ValidationException::withMessages([
                    'cashbond_payment' => 'Cashbond akan otomatis dipotong dari gaji. Pembayaran Cashbond terpisah dilakukan saat tidak ada pembayaran gaji.',
                ]);
            }

            if ($cashbondPaymentRequested > $cashbondBalance) {
                throw ValidationException::withMessages([
                    'cashbond_payment' => 'Pembayaran cashbond tidak boleh melebihi saldo hutang karyawan.',
                ]);
            }

            if ($salaryPaid <= 0 && $cashbondPaymentRequested <= 0) {
                throw ValidationException::withMessages([
                    'employee' => 'Tidak ada gaji atau pembayaran cashbond yang perlu dicatat.',
                ]);
            }

            $cashbondSalaryDeduction = min($salaryPaid, $cashbondBalance);
            $cashbondPaid = $salaryPaid > 0
                ? $cashbondSalaryDeduction
                : $cashbondPaymentRequested;
            $cashbondBalanceAfter = round($cashbondBalance - $cashbondPaid, 2);
            if ($cashbondPaid > 0) {
                $employee->update(['cashbond' => $cashbondBalanceAfter]);
            }

            PayrollPayment::create([
                'employee_id' => $employee->id,
                'period' => $period,
                'daily_pay_total' => $dailyPayTotal,
                'overtime_pay_total' => $overtimePayTotal,
                'piecework_pay_total' => $pieceworkPayTotal,
                'daily_salary_paid' => $dailySalaryPaid,
                'piecework_salary_paid' => $pieceworkSalaryPaid,
                'salary_paid' => $salaryPaid,
                'cashbond_paid' => $cashbondPaid,
                'cashbond_balance_after' => $cashbondBalanceAfter,
                'paid_by_id' => $request->user()->id,
                'paid_at' => now(),
            ]);

            return [
                'salary_paid' => $salaryPaid,
                'cashbond_salary_deduction' => $cashbondSalaryDeduction,
                'cashbond_paid' => $cashbondPaid,
            ];
        });

        $message = $payment['salary_paid'] > 0
            ? 'Gaji sebesar Rp '.number_format($payment['salary_paid'], 0, ',', '.').' berhasil dicatat.'
            : '';
        if ($payment['cashbond_salary_deduction'] > 0) {
            $netSalaryPaid = max($payment['salary_paid'] - $payment['cashbond_salary_deduction'], 0);
            $message .= ' Cashbond dipotong otomatis Rp '
                .number_format($payment['cashbond_salary_deduction'], 0, ',', '.')
                .'; gaji bersih yang diterima Rp '.number_format($netSalaryPaid, 0, ',', '.').'.';
        } elseif ($payment['cashbond_paid'] > 0) {
            $message .= ' Pembayaran cashbond sebesar Rp '
                .number_format($payment['cashbond_paid'], 0, ',', '.').' berhasil dicatat.';
        }

        return redirect()
            ->route('admin.payroll.index', [
                'period' => $period,
                'business_unit' => $employee->business_unit,
                'salary_type' => $salaryType,
            ])
            ->with('success', $message);
    }

    private function normalizeCashbondPayment(Request $request): void
    {
        $this->normalizeMoneyInput($request, 'cashbond_payment');
    }

    private function normalizeSalaryPayment(Request $request): void
    {
        $this->normalizeMoneyInput($request, 'salary_payment');
    }

    private function normalizeMoneyInput(Request $request, string $field): void
    {
        $amount = $request->input($field);
        if (! is_string($amount)) {
            return;
        }

        $amount = trim($amount);
        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $amount) === 1) {
            $request->merge([$field => str_replace(',', '', $amount)]);
        }
    }
}
