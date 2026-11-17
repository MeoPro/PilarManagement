<?php

use App\Models\AdminAttendance;
use App\Models\AttendanceSchedule;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function attendanceSchedulePayload(array $overrides = []): array
{
    return array_merge(AttendanceSchedule::DEFAULT_TIMES, $overrides);
}

test('owner can view and update attendance schedules', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('admin.attendance-schedule.edit'))
        ->assertOk()
        ->assertSeeText('Jadwal Absensi')
        ->assertSee('value="00:00"', false)
        ->assertSee('value="08:00"', false);

    $this->put(route('admin.attendance-schedule.update'), attendanceSchedulePayload([
        'employee_morning_start' => '07:15',
        'employee_morning_end' => '08:45',
        'employee_afternoon_start' => '12:15',
        'employee_afternoon_end' => '13:45',
        'kanban_morning_start' => '07:45',
        'kanban_morning_end' => '09:15',
        'kanban_afternoon_start' => '12:45',
        'kanban_afternoon_end' => '14:45',
    ]))
        ->assertRedirect(route('admin.attendance-schedule.edit'))
        ->assertSessionHas('success');

    $this->get(route('admin.sidebar-preferences.edit'))
        ->assertOk()
        ->assertSeeText('Jadwal Absensi');

    $this->assertDatabaseHas('attendance_schedules', [
        'id' => 1,
        'employee_morning_start' => '07:15',
        'employee_morning_end' => '08:45',
        'kanban_morning_start' => '07:45',
        'kanban_afternoon_end' => '14:45',
    ]);
});

test('only owners can view or update attendance schedules', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.attendance-schedule.edit'))
        ->assertForbidden();

    $this->put(route('admin.attendance-schedule.update'), attendanceSchedulePayload())
        ->assertForbidden();
});

test('attendance schedule windows require an end time after the start time', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->put(route('admin.attendance-schedule.update'), attendanceSchedulePayload([
            'employee_morning_start' => '09:00',
            'employee_morning_end' => '09:00',
            'kanban_afternoon_start' => '15:00',
            'kanban_afternoon_end' => '12:00',
        ]))
        ->assertSessionHasErrors([
            'employee_morning_end',
            'kanban_afternoon_end',
        ]);

    $this->assertDatabaseHas('attendance_schedules', [
        'id' => 1,
        'employee_morning_start' => '00:00:00',
        'employee_morning_end' => '09:00:00',
    ]);
});

test('employee attendance honors the configured opening and closing times', function () {
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)
        ->put(route('admin.attendance-schedule.update'), attendanceSchedulePayload([
            'employee_morning_start' => '08:15',
            'employee_morning_end' => '08:30',
        ]))
        ->assertRedirect();

    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'employee_type' => 'Harian',
        'started_at' => '2026-11-16',
        'status' => 'Aktif',
        'daily_wage' => 100000,
    ]);

    $this->actingAs($admin);

    $this->travelTo('2026-11-16 08:14:00');
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])
        ->assertSessionHas('error', 'Absensi sesi pertama belum dibuka.');

    $this->travelTo('2026-11-16 08:15:00');
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->travelTo('2026-11-16 08:30:00');
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])
        ->assertSessionHas('error', 'Waktu absensi sesi tersebut sudah ditutup pada pukul 08:30 WITA.');

    $this->assertDatabaseCount('employee_attendances', 1);
});

test('Kanban first-entry attendance honors the configured session windows', function () {
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)
        ->put(route('admin.attendance-schedule.update'), attendanceSchedulePayload([
            'kanban_morning_start' => '07:45',
            'kanban_morning_end' => '08:15',
            'kanban_afternoon_start' => '12:45',
            'kanban_afternoon_end' => '14:15',
        ]))
        ->assertRedirect();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->travelTo('2026-11-16 12:44:00');
    $this->post(route('kanban.tasks.store'), ['title' => 'Too early'])
        ->assertSessionHasErrors('title');

    $this->travelTo('2026-11-16 12:45:00');
    $this->post(route('kanban.tasks.store'), ['title' => 'Configured afternoon entry'])
        ->assertRedirect(route('kanban.index'));

    expect(AdminAttendance::query()->firstOrFail()->attendance_session)
        ->toBe('afternoon');
});
