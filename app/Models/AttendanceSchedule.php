<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSchedule extends Model
{
    public const DEFAULT_TIMES = [
        'employee_morning_start' => '00:00',
        'employee_morning_end' => '09:00',
        'employee_afternoon_start' => '12:00',
        'employee_afternoon_end' => '13:30',
        'kanban_morning_start' => '08:00',
        'kanban_morning_end' => '09:00',
        'kanban_afternoon_start' => '12:30',
        'kanban_afternoon_end' => '15:00',
    ];

    protected $fillable = [
        'employee_morning_start',
        'employee_morning_end',
        'employee_afternoon_start',
        'employee_afternoon_end',
        'kanban_morning_start',
        'kanban_morning_end',
        'kanban_afternoon_start',
        'kanban_afternoon_end',
    ];

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function employeeWindow(string $session): array
    {
        return match ($session) {
            'morning' => [
                'start' => substr($this->employee_morning_start, 0, 5),
                'end' => substr($this->employee_morning_end, 0, 5),
            ],
            'afternoon' => [
                'start' => substr($this->employee_afternoon_start, 0, 5),
                'end' => substr($this->employee_afternoon_end, 0, 5),
            ],
            default => throw new \InvalidArgumentException('Sesi absensi karyawan tidak valid.'),
        };
    }

    /**
     * @return array{start: string, end: string}
     */
    public function kanbanFirstEntryWindow(string $session): array
    {
        return match ($session) {
            'morning' => [
                'start' => substr($this->kanban_morning_start, 0, 5),
                'end' => substr($this->kanban_morning_end, 0, 5),
            ],
            'afternoon' => [
                'start' => substr($this->kanban_afternoon_start, 0, 5),
                'end' => substr($this->kanban_afternoon_end, 0, 5),
            ],
            default => throw new \InvalidArgumentException('Sesi Kanban tidak valid.'),
        };
    }
}
