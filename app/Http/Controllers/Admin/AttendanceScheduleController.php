<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceScheduleController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()->isOwner(), 403);

        return view('admin.preferences.attendance-schedule', [
            'schedule' => AttendanceSchedule::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);

        $rules = [];
        foreach (array_keys(AttendanceSchedule::DEFAULT_TIMES) as $field) {
            $rules[$field] = ['required', 'date_format:H:i'];
        }
        foreach ([
            ['employee_morning_start', 'employee_morning_end'],
            ['employee_afternoon_start', 'employee_afternoon_end'],
            ['kanban_morning_start', 'kanban_morning_end'],
            ['kanban_afternoon_start', 'kanban_afternoon_end'],
        ] as [$start, $end]) {
            $rules[$end][] = "after:{$start}";
        }

        $validated = $request->validate($rules);

        $schedule = AttendanceSchedule::query()->find(1);
        if ($schedule === null) {
            throw new \RuntimeException('Pengaturan jadwal absensi belum tersedia.');
        }

        $schedule->update($validated);

        return back()->with('success', 'Jadwal absensi berhasil diperbarui.');
    }
}
