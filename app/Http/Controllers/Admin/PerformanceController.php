<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAttendance;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\KanbanTaskStatusChange;
use App\Models\PieceworkAssignment;
use App\Models\PieceworkItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PerformanceController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'business_unit' => ['nullable', 'in:bali,factory'],
            'employee_type' => ['nullable', 'in:Harian,Borongan'],
            'admin_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $localToday = now($user->timezone())->startOfDay();
        $month = $validated['month'] ?? $localToday->format('Y-m');
        abort_if($month > $localToday->format('Y-m'), 422, 'Bulan yang dipilih belum dimulai.');

        $allowedUnits = $user->accessibleUnits();
        $canSelectUnit = ! $user->isOwner() && $user->canSelectBusinessUnit();
        $unitFilter = $canSelectUnit
            ? ($validated['business_unit'] ?? '')
            : ($user->activeBusinessUnit() ?? '');
        if (! in_array($unitFilter, ['', ...$allowedUnits], true)) {
            $unitFilter = '';
        }

        $selectedEmployeeType = $validated['employee_type'] ?? '';
        $employeeTypeFilter = $selectedEmployeeType;

        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01', $user->timezone())->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->min($localToday);
        $monthStartTimestamp = $monthStart->copy()->setTimezone(config('app.timezone'));
        $monthEndTimestamp = $monthEnd->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone'));
        $yearStart = $monthStart->copy()->startOfYear();
        $yearEnd = $monthEnd->copy();
        $yearStartTimestamp = $yearStart->copy()->setTimezone(config('app.timezone'));
        $yearEndTimestamp = $yearEnd->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone'));

        $employeeQuery = Employee::query()
            ->whereIn('business_unit', $allowedUnits)
            ->whereDate('started_at', '<=', $monthEnd->toDateString())
            ->where(function ($query) use ($yearStart): void {
                $query->whereNull('inactive_at')
                    ->orWhereDate('inactive_at', '>=', $yearStart->toDateString());
            })
            ->when($unitFilter !== '', fn ($query) => $query->where('business_unit', $unitFilter))
            ->when(
                $employeeTypeFilter !== '',
                fn ($query) => $query->where('employee_type', $employeeTypeFilter),
            )
            ->when($employeeTypeFilter === 'Borongan', function ($query) use ($monthStartTimestamp, $monthEndTimestamp): void {
                $query->where(function ($query) use ($monthStartTimestamp, $monthEndTimestamp): void {
                    $query->where('business_unit', '!=', User::UNIT_BALI)
                        ->orWhere(function ($query) use ($monthStartTimestamp, $monthEndTimestamp): void {
                            $query->where('business_unit', User::UNIT_BALI)
                                ->whereHas('pieceworkAssignments', fn ($assignments) => $assignments
                                    ->where('created_at', '>=', $monthStartTimestamp)
                                    ->where('created_at', '<', $monthEndTimestamp));
                        });
                });
            });
        $employees = (clone $employeeQuery)
            ->orderBy('full_name')
            ->paginate(50)
            ->withQueryString();
        $allEmployees = (clone $employeeQuery)
            ->orderBy('full_name')
            ->get();

        $performanceWorkType = $selectedEmployeeType !== '' ? $employeeTypeFilter : null;
        $employeeIds = $allEmployees->modelKeys();
        $monthlyPresentSessionsByEmployee = Employee::attendedSessionsForEmployees(
            $allEmployees,
            $monthStart,
            $monthEnd,
            $performanceWorkType,
        );
        $yearlyPresentSessionsByEmployee = Employee::attendedSessionsForEmployees(
            $allEmployees,
            $yearStart,
            $yearEnd,
            $performanceWorkType,
        );
        $attendanceByEmployee = EmployeeAttendance::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('attendance_date', '>=', $yearStart->toDateString())
            ->whereDate('attendance_date', '<=', $yearEnd->toDateString())
            ->get(['employee_id', 'attendance_date', 'attendance_status', 'work_type', 'piecework_amount'])
            ->groupBy('employee_id');
        $pieceworkByEmployee = PieceworkAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->withSum('pieceworkItems as produced_quantity', 'quantity')
            ->get(['employee_id', 'target_quantity', 'cancelled_quantity'])
            ->groupBy('employee_id');
        $pieceworkAssignmentsByEmployee = PieceworkAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('created_at', '>=', $yearStartTimestamp)
            ->where('created_at', '<', $yearEndTimestamp)
            ->withSum('pieceworkItems as produced_quantity', 'quantity')
            ->get(['employee_id', 'target_quantity', 'cancelled_quantity', 'target_deadline', 'completed_at', 'cancelled_at', 'created_at'])
            ->groupBy('employee_id');
        $standalonePieceworkByEmployee = PieceworkItem::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereNull('employee_attendance_id')
            ->where('created_at', '>=', $yearStartTimestamp)
            ->where('created_at', '<', $yearEndTimestamp)
            ->get(['employee_id', 'created_at', 'line_total'])
            ->groupBy('employee_id');
        $summaries = [];
        foreach ($allEmployees as $employee) {
            $records = $attendanceByEmployee->get($employee->id, collect());
            $monthlyRecords = $records->filter(
                fn (EmployeeAttendance $attendance): bool => $attendance->attendance_date->toDateString() >= $monthStart->toDateString()
                    && $attendance->attendance_date->toDateString() <= $monthEnd->toDateString(),
            );
            $yearlyRecords = $records->filter(
                fn (EmployeeAttendance $attendance): bool => $attendance->attendance_date->toDateString() >= $yearStart->toDateString()
                    && $attendance->attendance_date->toDateString() <= $yearEnd->toDateString(),
            );
            $standalonePieceworkRecords = $standalonePieceworkByEmployee->get($employee->id, collect());
            $monthlyStandalonePiecework = $standalonePieceworkRecords
                ->filter(fn (PieceworkItem $item): bool => $item->created_at->copy()->setTimezone($user->timezone())->toDateString() >= $monthStart->toDateString()
                    && $item->created_at->copy()->setTimezone($user->timezone())->toDateString() <= $monthEnd->toDateString())
                ->sum('line_total');
            $yearlyStandalonePiecework = $standalonePieceworkRecords->sum('line_total');
            $employeePieceworkAssignments = $pieceworkAssignmentsByEmployee->get($employee->id, collect());
            $monthlyAssignments = $employeePieceworkAssignments
                ->filter(fn (PieceworkAssignment $assignment): bool => $assignment->created_at->gte($monthStartTimestamp)
                    && $assignment->created_at->lt($monthEndTimestamp));
            $pieceworkPerformance = [
                'month' => $this->summarizePiecework($monthlyAssignments, $monthEnd, $user->timezone()),
                'year' => $this->summarizePiecework($employeePieceworkAssignments, $yearEnd, $user->timezone()),
            ];

            $summaries[$employee->id] = [
                'month' => $this->summarize(
                    $employee,
                    $monthlyRecords,
                    $monthStart,
                    $monthEnd,
                    $monthlyPresentSessionsByEmployee[$employee->id] ?? 0,
                    (float) $monthlyStandalonePiecework,
                    $employeeTypeFilter,
                ),
                'year' => $this->summarize(
                    $employee,
                    $yearlyRecords,
                    $yearStart,
                    $yearEnd,
                    $yearlyPresentSessionsByEmployee[$employee->id] ?? 0,
                    (float) $yearlyStandalonePiecework,
                    $employeeTypeFilter,
                ),
                'piecework' => [
                    'target_quantity' => (int) $pieceworkByEmployee
                        ->get($employee->id, collect())
                        ->sum(fn (PieceworkAssignment $assignment): int => max(
                            $assignment->target_quantity - $assignment->cancelled_quantity,
                            0,
                        )),
                    'produced_quantity' => (int) $pieceworkByEmployee->get($employee->id, collect())->sum('produced_quantity'),
                ],
                'piecework_performance' => $pieceworkPerformance,
            ];
        }

        $averageFor = function (string $period, string $metric) use ($summaries): float {
            return round((float) (collect($summaries)->avg(
                fn (array $summary): float => $summary[$period][$metric],
            ) ?? 0), 1);
        };
        $baliEmployeeSummaries = collect($summaries)
            ->only($allEmployees
                ->where('business_unit', User::UNIT_BALI)
                ->modelKeys());
        $averagePieceworkFor = function (string $period, string $metric) use ($baliEmployeeSummaries): ?float {
            $values = $baliEmployeeSummaries
                ->map(fn (array $summary): ?float => $summary['piecework_performance'][$period][$metric])
                ->filter(fn (?float $value): bool => $value !== null);

            return $values->isEmpty() ? null : round((float) $values->avg(), 1);
        };
        $dashboardSummary = [
            'month' => [
                'attendance_percentage' => $averageFor('month', 'attendance_percentage'),
                'piecework_completion_percentage' => $averagePieceworkFor('month', 'completion_percentage'),
                'piecework_on_time_percentage' => $averagePieceworkFor('month', 'on_time_percentage'),
            ],
        ];
        $selectedAdminId = $user->isOwner() && isset($validated['admin_id'])
            ? (int) $validated['admin_id']
            : null;
        $adminActivity = $this->adminActivitySummary(
            $user,
            $monthStart,
            $monthEnd,
            $selectedAdminId,
        );

        $businessUnits = [
            User::UNIT_BALI => 'Pilar Bali',
            User::UNIT_FACTORY => 'Pilar Factory',
        ];
        $employeeTypes = ['Harian', 'Borongan'];

        return view('admin.performance.index', compact(
            'employees',
            'summaries',
            'dashboardSummary',
            'month',
            'businessUnits',
            'unitFilter',
            'canSelectUnit',
            'employeeTypeFilter',
            'employeeTypes',
            'selectedAdminId',
            'adminActivity',
            'monthStart',
            'monthEnd',
        ));
    }

    /**
     * @param  Collection<int, PieceworkAssignment>  $assignments
     * @return array{target_quantity: int, produced_quantity: int, completion_percentage: float, due_assignments: int, on_time_assignments: int, on_time_percentage: ?float}
     */
    private function summarizePiecework(
        Collection $assignments,
        Carbon $evaluationEnd,
        string $timezone,
    ): array {
        $targetQuantity = (int) $assignments->sum('target_quantity');
        $targetQuantity -= (int) $assignments->sum('cancelled_quantity');
        $producedQuantity = (int) $assignments->sum('produced_quantity');
        $dueAssignments = $assignments->filter(
            fn (PieceworkAssignment $assignment): bool => $assignment->cancelled_at === null
                && $assignment->target_quantity > $assignment->cancelled_quantity
                && $assignment->target_deadline->toDateString() <= $evaluationEnd->toDateString(),
        );
        $onTimeAssignments = $dueAssignments->filter(
            fn (PieceworkAssignment $assignment): bool => $assignment->completed_at !== null
                && $assignment->cancelled_at === null
                && $assignment->completed_at->copy()->setTimezone($timezone)->toDateString() <= $assignment->target_deadline->toDateString(),
        )->count();

        return [
            'target_quantity' => $targetQuantity,
            'produced_quantity' => $producedQuantity,
            'completion_percentage' => $targetQuantity > 0
                ? round(min($producedQuantity, $targetQuantity) / $targetQuantity * 100, 1)
                : 0.0,
            'due_assignments' => $dueAssignments->count(),
            'on_time_assignments' => $onTimeAssignments,
            'on_time_percentage' => $dueAssignments->isNotEmpty()
                ? round($onTimeAssignments / $dueAssignments->count() * 100, 1)
                : null,
        ];
    }

    /**
     * @return array{admins: Collection<int, User>, dailyActivities: Collection<int, array<string, mixed>>}
     */
    private function adminActivitySummary(
        User $user,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?int $selectedAdminId,
    ): array {
        if (! $user->isOwner()) {
            return [
                'admins' => collect(),
                'dailyActivities' => collect(),
            ];
        }

        $admins = User::query()
            ->where('is_admin', true)
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereDate('created_at', '<=', $periodEnd->toDateString())
            ->orderBy('name')
            ->get(['id', 'name', 'business_unit', 'created_at']);
        if ($selectedAdminId !== null) {
            abort_unless($admins->contains('id', $selectedAdminId), 404);
        }

        $workingDates = Employee::workingDatesBetween($periodStart, $periodEnd);
        $adminIds = $admins->modelKeys();
        $adminTimezones = $admins->mapWithKeys(
            fn (User $admin): array => [$admin->id => $admin->timezone()],
        );
        $attendanceByAdminDate = AdminAttendance::query()
            ->whereIn('user_id', $adminIds)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->get()
            ->keyBy(fn (AdminAttendance $attendance): string => $attendance->user_id.':'.$attendance->attendance_date->toDateString());
        $afternoonStatusChangeKeys = KanbanTaskStatusChange::query()
            ->with('task:id,assignee_id,task_date')
            ->whereHas('task', fn ($query) => $query
                ->whereIn('assignee_id', $adminIds)
                ->whereDate('task_date', '>=', $periodStart->toDateString())
                ->whereDate('task_date', '<=', $periodEnd->toDateString()))
            ->get()
            ->filter(function (KanbanTaskStatusChange $statusChange) use ($adminTimezones): bool {
                $task = $statusChange->task;
                if ($task === null || (int) $statusChange->changed_by_id !== (int) $task->assignee_id) {
                    return false;
                }

                $timezone = $adminTimezones->get($task->assignee_id);
                if ($timezone === null) {
                    return false;
                }

                $localTime = $statusChange->changed_at
                    ->copy()
                    ->setTimezone($timezone)
                    ->format('H:i:s');

                return $localTime >= '12:30:00' && $localTime < '20:00:00';
            })
            ->mapWithKeys(fn (KanbanTaskStatusChange $statusChange): array => [
                $statusChange->task->assignee_id.':'.$statusChange->task->task_date->toDateString() => true,
            ]);
        $dailyActivityRows = [];

        foreach ($admins as $admin) {
            $firstEligibleDate = max($periodStart->toDateString(), $admin->created_at->toDateString());
            $expectedDays = count(array_filter(
                $workingDates,
                fn (string $date): bool => $date >= $firstEligibleDate,
            ));
            $attendanceDays = 0.0;
            $photoCount = 0;

            foreach ($workingDates as $date) {
                if ($date < $firstEligibleDate) {
                    continue;
                }

                $key = $admin->id.':'.$date;
                $attendance = $attendanceByAdminDate->get($key);
                $credit = $attendance?->attendanceCredit($afternoonStatusChangeKeys->has($key)) ?? 0.0;
                $photos = [
                    $attendance?->morning_photo_one_path,
                    $attendance?->morning_photo_two_path,
                    $attendance?->afternoon_photo_one_path,
                    $attendance?->afternoon_photo_two_path,
                ];
                $uploadedPhotoCount = count(array_filter($photos, fn ($path): bool => $path !== null && $path !== ''));
                $attendanceDays += $credit;
                $photoCount += $uploadedPhotoCount;
                $dailyActivityRows[] = [
                    'attendance_date' => $date,
                    'admin' => $admin,
                    'attendance_id' => $attendance?->id,
                    'morning_complete' => $attendance?->sessionPhotosComplete('morning') ?? false,
                    'afternoon_complete' => $attendance?->sessionPhotosComplete('afternoon') ?? false,
                    'morning_photo_one_path' => $attendance?->morning_photo_one_path,
                    'morning_photo_two_path' => $attendance?->morning_photo_two_path,
                    'afternoon_photo_one_path' => $attendance?->afternoon_photo_one_path,
                    'afternoon_photo_two_path' => $attendance?->afternoon_photo_two_path,
                    'uploaded_photo_count' => $uploadedPhotoCount,
                    'attendance_credit' => $credit,
                ];
            }

            $admin->setAttribute('monthly_photo_count', $photoCount);
            $admin->setAttribute('monthly_photo_target', max($expectedDays * 4, 0));
            $admin->setAttribute('monthly_attendance_days', min($attendanceDays, (float) $expectedDays));
            $admin->setAttribute('monthly_expected_days', $expectedDays);
            $admin->setAttribute(
                'monthly_attendance_percentage',
                $expectedDays > 0 ? round(min($attendanceDays, (float) $expectedDays) / $expectedDays * 100, 1) : 100.0,
            );
        }

        $dailyActivities = collect($dailyActivityRows);
        if ($selectedAdminId !== null) {
            $dailyActivities = $dailyActivities
                ->filter(fn (array $activity): bool => (int) $activity['admin']->id === $selectedAdminId)
                ->values();
        }

        return [
            'admins' => $admins,
            'dailyActivities' => $dailyActivities,
        ];
    }

    /**
     * @param  Collection<int, EmployeeAttendance>  $records
     * @return array{attendance_percentage: float, present: int, izin: int, alpa: int, absence_percentage: float, izin_percentage: float, alpa_percentage: float, izin_share: float, alpa_share: float, alpa_color: string, piecework_wage: float}
     */
    private function summarize(
        Employee $employee,
        Collection $records,
        Carbon $start,
        Carbon $end,
        int $present,
        float $standalonePieceworkAmount,
        string $workTypeFilter,
    ): array {
        $attendanceRecords = $workTypeFilter !== ''
            ? $records->where('work_type', $workTypeFilter)
            : $records;
        $izin = $attendanceRecords->where('attendance_status', 'Izin')->count();
        $recordedAlpa = $attendanceRecords->where('attendance_status', 'Alpa')->count();
        $expectedSessions = $this->expectedSessions($employee, $start, $end);
        $additionalPresentSessions = max(
            $present - $attendanceRecords->where('attendance_status', 'Hadir')->count(),
            0,
        );
        $missingSessions = max(
            $expectedSessions - $attendanceRecords->count() - $additionalPresentSessions,
            0,
        );
        $alpa = $recordedAlpa + $missingSessions;
        $absences = $izin + $alpa;
        $absencePercentage = $expectedSessions > 0
            ? round($absences / $expectedSessions * 100, 1)
            : 0.0;
        $alpaShare = $absences > 0
            ? round($alpa / $absences * 100, 1)
            : 0.0;
        $izinShare = $absences > 0
            ? round($izin / $absences * 100, 1)
            : 0.0;

        return [
            'attendance_percentage' => $expectedSessions > 0
                ? round(min($present, $expectedSessions) / $expectedSessions * 100, 1)
                : 0.0,
            'present' => $present,
            'izin' => $izin,
            'alpa' => $alpa,
            'absence_percentage' => $absencePercentage,
            'izin_percentage' => $expectedSessions > 0
                ? round($izin / $expectedSessions * 100, 1)
                : 0.0,
            'alpa_percentage' => $expectedSessions > 0
                ? round($alpa / $expectedSessions * 100, 1)
                : 0.0,
            'izin_share' => $izinShare,
            'alpa_share' => $alpaShare,
            'piecework_wage' => (float) $records
                ->where('attendance_status', 'Hadir')
                ->where('work_type', 'Borongan')
                ->sum('piecework_amount')
                + $standalonePieceworkAmount,
            'alpa_color' => match (true) {
                $alpaShare >= 80 => 'red',
                $alpaShare >= 50 => 'orange',
                $alpaShare >= 10 => 'yellow',
                default => 'green',
            },
        ];
    }

    private function expectedSessions(Employee $employee, Carbon $start, Carbon $end): int
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        $employmentStart = $employee->started_at->copy()->startOfDay();
        $employmentEnd = $employee->status === 'Nonaktif'
            ? ($employee->inactive_at ?? $end)
            : $end;
        $employmentEnd = $employmentEnd->copy()->startOfDay();

        if ($employmentStart->gt($start)) {
            $start = $employmentStart;
        }
        if ($employmentEnd->lt($end)) {
            $end = $employmentEnd;
        }
        if ($start->gt($end)) {
            return 0;
        }

        return count(Employee::workingDatesBetween($start, $end)) * 2;
    }
}
