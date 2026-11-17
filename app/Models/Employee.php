<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'photo_path',
        'address',
        'phone',
        'email',
        'business_unit',
        'employee_type',
        'cashbond',
        'daily_wage',
        'overtime_hourly_rate',
        'started_at',
        'status',
        'inactive_at',
    ];

    protected $casts = [
        'cashbond' => 'decimal:2',
        'daily_wage' => 'decimal:2',
        'overtime_hourly_rate' => 'decimal:2',
        'started_at' => 'date',
        'inactive_at' => 'date',
    ];

    public function supportsWorkType(string $workType): bool
    {
        return $this->employee_type === $workType;
    }

    public function canRecordBaliBoronganAttendance(): bool
    {
        return $this->business_unit === User::UNIT_BALI
            && $this->employee_type === 'Borongan';
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    public function overtimeEntries(): HasMany
    {
        return $this->hasMany(EmployeeOvertime::class);
    }

    public function payrollPayments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
    }

    public function cashbondAdvances(): HasMany
    {
        return $this->hasMany(CashbondAdvance::class);
    }

    public function pieceworkAssignments(): HasMany
    {
        return $this->hasMany(PieceworkAssignment::class);
    }

    public function finishingAssignments(): HasMany
    {
        return $this->hasMany(FinishingAssignment::class);
    }

    public function pieceworkItems(): HasMany
    {
        return $this->hasMany(PieceworkItem::class);
    }

    public function getMonthlyAttendancePercentageAttribute(): float
    {
        $attendedSessions = isset($this->attributes['monthly_attendances_count'])
            ? (int) $this->attributes['monthly_attendances_count']
            : null;

        return $this->attendancePercentageForPeriod(
            today()->startOfMonth(),
            today(),
            $attendedSessions,
        );
    }

    public function attendancePercentageForPeriod(
        Carbon $periodStart,
        Carbon $periodEnd,
        ?int $attendedSessions = null,
    ): float {
        if (! $this->started_at) {
            return 0;
        }

        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->startOfDay();
        $employmentStart = $this->started_at->copy()->startOfDay();
        $employmentEnd = $this->status === 'Nonaktif'
            ? ($this->inactive_at ?? $periodEnd)
            : $periodEnd;
        $employmentEnd = $employmentEnd->copy()->startOfDay();

        if ($employmentStart->gt($periodStart)) {
            $periodStart = $employmentStart;
        }
        if ($employmentEnd->lt($periodEnd)) {
            $periodEnd = $employmentEnd;
        }
        if ($periodStart->gt($periodEnd)) {
            return 0;
        }

        $workingDates = self::workingDatesBetween($periodStart, $periodEnd);
        $expectedSessions = count($workingDates) * 2;
        if ($expectedSessions === 0) {
            return 100.0;
        }

        $attendedSessions ??= self::attendedSessionsForEmployees(
            collect([$this]),
            $periodStart,
            $periodEnd,
        )[$this->id] ?? 0;

        return round(min($attendedSessions, $expectedSessions) / $expectedSessions * 100, 1);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, int>
     */
    public static function attendedSessionsForEmployees(
        Collection $employees,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?string $workType = null,
    ): array {
        $employees = $employees->keyBy('id');
        if ($employees->isEmpty()) {
            return [];
        }

        $workingDates = self::workingDatesBetween($periodStart, $periodEnd);
        $attendancesByEmployee = EmployeeAttendance::query()
            ->whereIn('employee_id', $employees->keys()->all())
            ->whereIn(DB::raw('DATE(attendance_date)'), $workingDates)
            ->where('attendance_status', 'Hadir')
            ->when($workType !== null, fn ($query) => $query->where('work_type', $workType))
            ->get(['employee_id', 'attendance_date', 'work_type'])
            ->groupBy('employee_id');
        $sessionsByEmployee = [];

        foreach ($employees as $employee) {
            $sessionsByEmployee[$employee->id] = $attendancesByEmployee
                ->get($employee->id, collect())
                ->count();
        }

        return $sessionsByEmployee;
    }

    /**
     * @return list<string>
     */
    public static function workingDatesBetween(Carbon $periodStart, Carbon $periodEnd): array
    {
        $workingDates = [];

        for ($date = $periodStart->copy()->startOfDay(); $date->lte($periodEnd); $date->addDay()) {
            if (! $date->isSunday()) {
                $workingDates[] = $date->toDateString();
            }
        }

        return $workingDates;
    }
}
