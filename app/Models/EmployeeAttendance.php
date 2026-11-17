<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAttendance extends Model
{
    protected $fillable = [
        'employee_id',
        'attendance_date',
        'session',
        'work_type',
        'attendance_timezone',
        'attendance_status',
        'status_note',
        'recorded_by_id',
        'corrected_by_id',
        'corrected_at',
        'correction_reason',
        'daily_pay_amount',
        'piecework_amount',
        'attended_at',
        'overtime_hours',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'attended_at' => 'datetime',
        'corrected_at' => 'datetime',
        'overtime_hours' => 'decimal:2',
        'daily_pay_amount' => 'decimal:2',
        'piecework_amount' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function pieceworkItems(): HasMany
    {
        return $this->hasMany(PieceworkItem::class);
    }

    public function getLateMinutesAttribute(): int
    {
        if ($this->attendance_status !== 'Hadir') {
            return 0;
        }

        $timezone = $this->attendance_timezone
            ?? User::timezoneForUnit($this->employee?->business_unit);
        $localAttendanceTime = $this->attended_at->copy()->setTimezone($timezone);
        $scheduledStartMinutes = $this->session === 'morning' ? 8 * 60 : 13 * 60;
        $actualMinutes = ((int) $localAttendanceTime->format('H') * 60) + (int) $localAttendanceTime->format('i');

        return max(0, $actualMinutes - $scheduledStartMinutes);
    }

    public function getIsLateAttribute(): bool
    {
        return $this->late_minutes > 0;
    }
}
