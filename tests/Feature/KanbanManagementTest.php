<?php

use App\Mail\KanbanActivityReminder;
use App\Mail\KanbanAttendanceSummary;
use App\Models\AdminAttendance;
use App\Models\KanbanTask;
use App\Models\KanbanTaskStatusChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('admins can see the shared board but can only create and move their own tasks', function () {
    Storage::fake('local');
    $this->travelTo('2026-11-16 08:30:00');
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->factoryAdmin()->create();
    $otherTask = KanbanTask::factory()
        ->for($otherAdmin, 'assignee')
        ->for($otherAdmin, 'creator')
        ->create(['title' => 'Private factory task']);

    $this->actingAs($admin)
        ->get(route('admin.index'))
        ->assertSeeText('Kanban');

    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), [
            'title' => 'Follow up with customer',
            'description' => 'Confirm the delivery date.',
        ])
        ->assertRedirect(route('kanban.index'));

    $task = KanbanTask::query()->where('title', 'Follow up with customer')->firstOrFail();
    expect($task->assignee_id)->toBe($admin->id)
        ->and($task->created_by_id)->toBe($admin->id);

    $this->get(route('kanban.index'))
        ->assertSeeText('Follow up with customer')
        ->assertSeeText('Private factory task')
        ->assertSee('aria-label="Tambah tugas"', false)
        ->assertDontSee('+ Add Task');

    $this->post(route('kanban.tasks.store'), [
        'assignee_id' => $otherAdmin->id,
        'title' => 'Unauthorized task assignment',
    ])->assertForbidden();

    $this->assertDatabaseMissing('kanban_tasks', [
        'title' => 'Unauthorized task assignment',
    ]);

    $this->patch(route('kanban.tasks.status', $task), [
        'status' => 'proses',
        'photo' => UploadedFile::fake()->create('in-progress.jpg', 100, 'image/jpeg'),
    ], ['Accept' => 'application/json'])
        ->assertJsonPath('task.status', 'proses');

    $this->assertDatabaseHas('kanban_tasks', [
        'id' => $task->id,
        'status' => 'proses',
    ]);

    $this->patchJson(route('kanban.tasks.status', $task), ['status' => 'invalid'])
        ->assertInvalid(['status']);

    $this->assertDatabaseHas('kanban_tasks', [
        'id' => $task->id,
        'status' => 'proses',
    ]);

    $this->patchJson(route('kanban.tasks.status', $otherTask), ['status' => 'selesai'])
        ->assertForbidden();
    $this->delete(route('kanban.tasks.destroy', $otherTask))
        ->assertForbidden();
});

test('owner can view every admin board, filter by admin, and assign a task', function () {
    Storage::fake('local');
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->factoryAdmin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $assignedTask = KanbanTask::factory()
        ->for($admin, 'assignee')
        ->for($owner, 'creator')
        ->create(['title' => 'Factory task']);
    KanbanTask::factory()
        ->for($otherAdmin, 'assignee')
        ->for($owner, 'creator')
        ->create(['title' => 'Bali task']);

    $this->actingAs($owner)
        ->get(route('kanban.index'))
        ->assertSeeText('Factory task')
        ->assertSeeText('Bali task');

    $this->get(route('kanban.index', ['admin_id' => $admin->id]))
        ->assertSeeText('Factory task')
        ->assertDontSeeText('Bali task')
        ->assertDontSeeText('Ringkasan Kehadiran Admin');

    $this->post(route('kanban.tasks.store'), [
        'assignee_id' => $admin->id,
        'title' => 'Review factory schedule',
    ])->assertRedirect(route('kanban.index'));

    $this->assertDatabaseHas('kanban_tasks', [
        'assignee_id' => $admin->id,
        'created_by_id' => $owner->id,
        'title' => 'Review factory schedule',
    ]);

    $this->patch(route('kanban.tasks.status', $assignedTask), [
        'status' => 'selesai',
        'photo' => UploadedFile::fake()->create('completed.jpg', 100, 'image/jpeg'),
    ], ['Accept' => 'application/json'])
        ->assertJsonPath('task.status', 'selesai');
});

test('admin sees an alert for an owner task until opening the Kanban board', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($owner)
        ->post(route('kanban.tasks.store'), [
            'assignee_id' => $admin->id,
            'title' => 'Prepare weekly sales report',
        ])
        ->assertRedirect(route('kanban.index'));

    $task = KanbanTask::query()->where('title', 'Prepare weekly sales report')->firstOrFail();
    expect($task->read_at)->toBeNull();

    $this->actingAs($admin)
        ->get(route('admin.index'))
        ->assertSee('1 tugas baru dari Owner', false);

    $this->get(route('kanban.index'))
        ->assertDontSee('tugas baru dari Owner', false);

    expect($task->fresh()->read_at)->not->toBeNull();
});

test('daily boards archive previous tasks and record attendance again when an admin adds a task', function () {
    $this->travelTo('2026-11-16 08:30:00');
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), [
            'title' => 'Nov 16 activity',
        ])
        ->assertRedirect(route('kanban.index'));

    $this->assertDatabaseHas('kanban_tasks', ['title' => 'Nov 16 activity']);
    expect(KanbanTask::query()
        ->where('title', 'Nov 16 activity')
        ->firstOrFail()
        ->task_date->toDateString())->toBe('2026-11-16');
    $this->assertDatabaseCount('admin_attendances', 1);

    $this->travelTo('2026-11-17 08:30:00');
    $this->post(route('kanban.tasks.store'), [
        'title' => 'Nov 17 activity',
    ])->assertRedirect(route('kanban.index'));

    $this->get(route('kanban.index'))
        ->assertSeeText('Nov 17 activity')
        ->assertDontSeeText('Nov 16 activity');

    $this->assertDatabaseCount('admin_attendances', 2);
    $this->assertDatabaseHas('kanban_tasks', ['title' => 'Nov 16 activity']);

    $this->actingAs($owner)
        ->get(route('kanban.index', ['task_date' => '2026-11-16']))
        ->assertSeeText('Nov 16 activity')
        ->assertDontSeeText('Nov 17 activity')
        ->assertSeeText('hanya-baca');

    $archivedTask = KanbanTask::query()->where('title', 'Nov 16 activity')->firstOrFail();

    $this->actingAs($admin)
        ->patchJson(route('kanban.tasks.status', $archivedTask), ['status' => 'selesai'])
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete(route('kanban.tasks.destroy', $archivedTask))
        ->assertForbidden();

    $this->assertModelExists($archivedTask);
});

test('admins can add tasks after the morning entry window once their attendance is recorded', function () {
    $this->travelTo('2026-11-16 08:59:00');
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'Morning task'])
        ->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 11:00:00');
    $this->post(route('kanban.tasks.store'), ['title' => 'Additional task'])
        ->assertRedirect(route('kanban.index'));

    expect(AdminAttendance::query()->firstOrFail()->attendance_session)->toBe('morning');
    $this->assertDatabaseCount('kanban_tasks', 2);
});

test('admins who miss the morning window can first enter only from 12:30 to 15:00', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->travelTo('2026-11-16 09:00:00');
    $this->post(route('kanban.tasks.store'), ['title' => 'Too late for morning'])
        ->assertSessionHasErrors('title');

    $this->travelTo('2026-11-16 12:29:59');
    $this->post(route('kanban.tasks.store'), ['title' => 'Too early for afternoon'])
        ->assertSessionHasErrors('title');

    $this->travelTo('2026-11-16 12:30:00');
    $this->post(route('kanban.tasks.store'), ['title' => 'Afternoon task'])
        ->assertRedirect(route('kanban.index'));

    $afternoonAdmin = User::factory()->admin()->create();
    $this->actingAs($afternoonAdmin);
    $this->travelTo('2026-11-16 15:00:00');
    $this->post(route('kanban.tasks.store'), ['title' => 'Too late for afternoon'])
        ->assertSessionHasErrors('title');

    expect(AdminAttendance::query()
        ->where('user_id', $admin->id)
        ->firstOrFail()
        ->attendance_session)->toBe('afternoon');
    $this->assertDatabaseCount('kanban_tasks', 1);
});

test('absen pulang at noon closes the admin Kanban for the day', function () {
    $this->travelTo('2026-11-16 08:30:00');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'Daily task'])
        ->assertRedirect(route('kanban.index'));
    $task = KanbanTask::query()->where('title', 'Daily task')->firstOrFail();

    $this->travelTo('2026-11-16 11:59:59');
    $this->get(route('kanban.index'))->assertDontSeeText('Absen Pulang dan Tutup Kanban');

    $this->travelTo('2026-11-16 12:00:00');
    $this->get(route('kanban.index'))->assertSeeText('Absen Pulang dan Tutup Kanban');
    $this->post(route('kanban.attendance.checkout'))
        ->assertRedirect(route('kanban.index'))
        ->assertSessionHas('success', 'Absen pulang berhasil. Kanban hari ini telah ditutup.');

    expect(AdminAttendance::query()->firstOrFail()->checked_out_at)->not->toBeNull();
    $this->patchJson(route('kanban.tasks.status', $task), ['status' => 'proses'])
        ->assertForbidden();
    $this->post(route('kanban.tasks.store'), ['title' => 'After checkout'])
        ->assertForbidden();

    $this->get(route('kanban.index'))
        ->assertSeeText('Kanban hari ini sudah ditutup setelah absen pulang atau pukul 20.00.');
});

test('absen pulang is unavailable between the noon and evening windows', function () {
    $this->travelTo('2026-11-16 08:30:00');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'Evening checkout task'])
        ->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 13:00:00');
    $this->get(route('kanban.index'))->assertDontSeeText('Absen Pulang dan Tutup Kanban');
    $this->post(route('kanban.attendance.checkout'))->assertForbidden();

    $this->travelTo('2026-11-16 16:59:59');
    $this->get(route('kanban.index'))->assertDontSeeText('Absen Pulang dan Tutup Kanban');
    $this->post(route('kanban.attendance.checkout'))->assertForbidden();

    $this->travelTo('2026-11-16 17:00:00');
    $this->get(route('kanban.index'))->assertSeeText('Absen Pulang dan Tutup Kanban');
    $this->post(route('kanban.attendance.checkout'))
        ->assertRedirect(route('kanban.index'))
        ->assertSessionHas('success', 'Absen pulang berhasil. Kanban hari ini telah ditutup.');
});

test('Kanban closes at 20:00 and blocks further admin changes', function () {
    $this->travelTo('2026-11-16 08:30:00');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'Closing task'])
        ->assertRedirect(route('kanban.index'));
    $task = KanbanTask::query()->where('title', 'Closing task')->firstOrFail();

    $this->travelTo('2026-11-16 20:00:00');
    $this->get(route('kanban.index'))
        ->assertSeeText('Kanban hari ini sudah ditutup setelah absen pulang atau pukul 20.00.');
    $this->post(route('kanban.tasks.store'), ['title' => 'After close'])
        ->assertForbidden();
    $this->patchJson(route('kanban.tasks.status', $task), ['status' => 'proses'])
        ->assertForbidden();
});

test('Kanban status changes require photos and retain history after photo cleanup', function () {
    Storage::fake('local');
    $this->travelTo('2026-11-16 08:30:00');
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->factoryAdmin()->create();
    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'Evidence task'])
        ->assertRedirect(route('kanban.index'));
    $task = KanbanTask::query()->where('title', 'Evidence task')->firstOrFail();

    $this->patchJson(route('kanban.tasks.status', $task), ['status' => 'proses'])
        ->assertInvalid(['photo']);
    $this->assertDatabaseHas('kanban_tasks', [
        'id' => $task->id,
        'status' => 'plan',
    ]);

    $this->patch(route('kanban.tasks.status', $task), [
        'status' => 'proses',
        'photo' => UploadedFile::fake()->create('in-progress.jpg', 100, 'image/jpeg'),
    ], ['Accept' => 'application/json'])
        ->assertJsonPath('task.status_change.to_status_label', 'Proses');
    $processChange = KanbanTaskStatusChange::query()
        ->where('kanban_task_id', $task->id)
        ->where('to_status', 'proses')
        ->firstOrFail();
    Storage::disk('local')->assertExists($processChange->photo_path);

    $this->patch(route('kanban.tasks.status', $task), [
        'status' => 'selesai',
        'photo' => UploadedFile::fake()->create('evidence.jpg', 100, 'image/jpeg'),
    ])->assertRedirect(route('kanban.index'));

    $photoChange = KanbanTaskStatusChange::query()
        ->where('kanban_task_id', $task->id)
        ->where('to_status', 'selesai')
        ->firstOrFail();
    Storage::disk('local')->assertExists($photoChange->photo_path);

    $this->actingAs($otherAdmin)
        ->get(route('kanban.status-changes.photo', $photoChange))
        ->assertOk();

    $this->travelTo('2026-12-17 12:00:00');
    Artisan::call('kanban:prune-photos');

    Storage::disk('local')->assertMissing($photoChange->photo_path);
    $this->assertDatabaseHas('kanban_task_status_changes', [
        'id' => $photoChange->id,
        'photo_path' => null,
    ]);
    $this->assertDatabaseHas('kanban_tasks', ['id' => $task->id]);
    $this->actingAs($otherAdmin)
        ->get(route('kanban.status-changes.photo', $photoChange))
        ->assertNotFound();
});

test('session photos are separate, visible in the owner daily report, and pruned after a month', function () {
    Storage::fake('local');
    $this->travelTo('2026-11-16 08:30:00');
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'Session evidence task'])
        ->assertRedirect(route('kanban.index'));
    $attendance = AdminAttendance::query()->firstOrFail();

    $this->post(route('kanban.attendance.session-photos.store', 'morning'), [
        'photos' => [
            UploadedFile::fake()->create('morning-one.jpg', 100, 'image/jpeg'),
        ],
    ])->assertSessionHasErrors('photos');

    $this->post(route('kanban.attendance.session-photos.store', 'morning'), [
        'photos' => [
            UploadedFile::fake()->create('morning-one.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('morning-two.jpg', 100, 'image/jpeg'),
        ],
    ])
        ->assertRedirect(route('kanban.index'))
        ->assertSessionHas('success', 'Dua foto sesi pagi berhasil dicatat.');

    $attendance->refresh();
    Storage::disk('local')->assertExists($attendance->morning_photo_one_path);
    Storage::disk('local')->assertExists($attendance->morning_photo_two_path);

    $this->travelTo('2026-11-16 13:00:00');
    $this->post(route('kanban.attendance.session-photos.store', 'afternoon'), [
        'photos' => [
            UploadedFile::fake()->create('afternoon-one.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('afternoon-two.jpg', 100, 'image/jpeg'),
        ],
    ])->assertRedirect(route('kanban.index'));

    $attendance->refresh();
    $reportResponse = $this->actingAs($owner)
        ->get(route('admin.performance.index', [
            'month' => '2026-11',
            'admin_id' => $admin->id,
        ]));
    $reportResponse->assertOk();
    $activity = $reportResponse->viewData('adminActivity')['dailyActivities']
        ->first(fn (array $activity): bool => (int) $activity['admin']->id === (int) $admin->id);
    expect($activity['attendance_id'])->toBe($attendance->id)
        ->and($activity['morning_complete'])->toBeTrue()
        ->and($activity['afternoon_complete'])->toBeTrue()
        ->and($activity['attendance_credit'])->toBe(1.0);
    $reportResponse
        ->assertSeeText('Ringkasan Aktivitas Admin · 11/2026')
        ->assertSeeText('Aktivitas harian admin')
        ->assertSeeText($admin->name)
        ->assertSeeText('Foto 4/4')
        ->assertSeeText('Lengkap · 2/2')
        ->assertSeeText('1,0 hari')
        ->assertSee('admin_id='.$admin->id, false)
        ->assertSee(route('kanban.attendance.session-photos.show', [$attendance, 'morning', 1]), false);

    $this->get(route('kanban.attendance.session-photos.show', [$attendance, 'morning', 1]))
        ->assertOk();

    $this->travelTo('2026-12-17 12:00:00');
    Artisan::call('kanban:prune-photos');
    Storage::disk('local')->assertMissing($attendance->morning_photo_one_path);
    Storage::disk('local')->assertMissing($attendance->morning_photo_two_path);

    $attendance->refresh();
    expect($attendance->morning_photos_uploaded_at)->not->toBeNull()
        ->and($attendance->afternoon_photos_uploaded_at)->not->toBeNull();
    $this->assertDatabaseHas('admin_attendances', [
        'id' => $attendance->id,
        'morning_photo_one_path' => null,
        'morning_photo_two_path' => null,
        'afternoon_photo_one_path' => null,
        'afternoon_photo_two_path' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('admin.performance.index', [
            'month' => '2026-11',
            'admin_id' => $admin->id,
        ]))
        ->assertSeeText('Lengkap · 2/2')
        ->assertSeeText('1,0 hari');
    $this->get(route('kanban.attendance.session-photos.show', [$attendance, 'morning', 1]))
        ->assertNotFound();
});

test('afternoon-only evidence earns half a day and emails the admin after 20:00', function () {
    Storage::fake('local');
    Mail::fake();
    $this->travelTo('2026-11-16 12:30:00');
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'Afternoon-only task'])
        ->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 13:00:00');
    $this->post(route('kanban.attendance.session-photos.store', 'afternoon'), [
        'photos' => [
            UploadedFile::fake()->create('afternoon-one.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('afternoon-two.jpg', 100, 'image/jpeg'),
        ],
    ])->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 20:00:00');
    Artisan::call('kanban:send-reminders');
    Mail::assertSent(KanbanAttendanceSummary::class, function (KanbanAttendanceSummary $mail) use ($admin): bool {
        return $mail->attendanceCredit === 0.5
            && $mail->hasTo($admin->email);
    });
    $this->assertDatabaseHas('kanban_notification_logs', [
        'user_id' => $admin->id,
        'notification_type' => 'admin_attendance_summary',
        'notification_date' => '2026-11-16',
    ]);

    $this->actingAs($owner)
        ->get(route('admin.performance.index', [
            'month' => '2026-11',
            'admin_id' => $admin->id,
        ]))
        ->assertSeeText('0,5 hari');
});

test('afternoon photos count as a full day when Kanban was started on time', function () {
    Storage::fake('local');
    $this->travelTo('2026-11-16 08:30:00');
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'On-time morning start'])
        ->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 13:00:00');
    $this->post(route('kanban.attendance.session-photos.store', 'afternoon'), [
        'photos' => [
            UploadedFile::fake()->create('afternoon-one.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('afternoon-two.jpg', 100, 'image/jpeg'),
        ],
    ])->assertRedirect(route('kanban.index'));

    $this->actingAs($owner)
        ->get(route('admin.performance.index', [
            'month' => '2026-11',
            'admin_id' => $admin->id,
        ]))
        ->assertSeeText('Belum lengkap')
        ->assertSeeText('1,0 hari')
        ->assertSeeText('100,0%');
});

test('afternoon status movement completes attendance when morning photos are complete', function () {
    Storage::fake('local');
    $this->travelTo('2026-11-16 08:30:00');
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), ['title' => 'Morning evidence task'])
        ->assertRedirect(route('kanban.index'));
    $task = KanbanTask::query()->where('title', 'Morning evidence task')->firstOrFail();

    $this->post(route('kanban.attendance.session-photos.store', 'morning'), [
        'photos' => [
            UploadedFile::fake()->create('morning-one.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('morning-two.jpg', 100, 'image/jpeg'),
        ],
    ])->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 13:00:00');
    $this->patch(route('kanban.tasks.status', $task), [
        'status' => 'proses',
        'photo' => UploadedFile::fake()->create('afternoon-status.jpg', 100, 'image/jpeg'),
    ], ['Accept' => 'application/json'])
        ->assertJsonPath('task.status', 'proses');
    $statusChange = KanbanTaskStatusChange::query()
        ->where('kanban_task_id', $task->id)
        ->firstOrFail();
    expect($statusChange->changed_by_id)->toBe($admin->id)
        ->and($statusChange->changed_at->copy()->setTimezone($admin->timezone())->format('H:i:s'))->toBe('13:00:00');

    $this->actingAs($owner)
        ->get(route('admin.performance.index', [
            'month' => '2026-11',
            'admin_id' => $admin->id,
        ]))
        ->assertSeeText('1,0 hari')
        ->assertSeeText('100,0%');
});

test('Kanban reminders email admins at 18 and owners at 20 every day', function () {
    Mail::fake();
    $this->travelTo('2026-11-16 08:30:00');
    $owner = User::factory()->owner()->create();
    $unchangedAdmin = User::factory()->admin()->create();
    $updatedAdmin = User::factory()->admin()->create();

    $this->actingAs($unchangedAdmin)
        ->post(route('kanban.tasks.store'), ['title' => 'Waiting task'])
        ->assertRedirect(route('kanban.index'));
    $this->post(route('kanban.attendance.session-photos.store', 'morning'), [
        'photos' => [
            UploadedFile::fake()->create('waiting-morning-one.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('waiting-morning-two.jpg', 100, 'image/jpeg'),
        ],
    ])->assertRedirect(route('kanban.index'));
    $this->actingAs($updatedAdmin)
        ->post(route('kanban.tasks.store'), ['title' => 'Updated task'])
        ->assertRedirect(route('kanban.index'));
    $updatedTask = KanbanTask::query()->where('title', 'Updated task')->firstOrFail();

    $this->post(route('kanban.attendance.session-photos.store', 'morning'), [
        'photos' => [
            UploadedFile::fake()->create('updated-morning-one.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('updated-morning-two.jpg', 100, 'image/jpeg'),
        ],
    ])->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 13:00:00');
    $this->actingAs($unchangedAdmin)
        ->post(route('kanban.attendance.session-photos.store', 'afternoon'), [
            'photos' => [
                UploadedFile::fake()->create('waiting-afternoon-one.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('waiting-afternoon-two.jpg', 100, 'image/jpeg'),
            ],
        ])->assertRedirect(route('kanban.index'));
    $this->actingAs($updatedAdmin)
        ->post(route('kanban.attendance.session-photos.store', 'afternoon'), [
            'photos' => [
                UploadedFile::fake()->create('updated-afternoon-one.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('updated-afternoon-two.jpg', 100, 'image/jpeg'),
            ],
        ])->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 17:00:00');
    $this->actingAs($updatedAdmin)
        ->patch(route('kanban.tasks.status', $updatedTask), [
            'status' => 'proses',
            'photo' => UploadedFile::fake()->create('updated-status.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
        ->assertJsonPath('task.status', 'proses');

    $this->travelTo('2026-11-16 18:00:00');
    Artisan::call('kanban:send-reminders');
    Artisan::call('kanban:send-reminders');
    Mail::assertSent(KanbanActivityReminder::class, function (KanbanActivityReminder $mail) use ($unchangedAdmin): bool {
        return ! $mail->ownerAlert
            && $mail->adminNames === [$unchangedAdmin->name]
            && $mail->to[0]['address'] === $unchangedAdmin->email;
    });

    $this->travelTo('2026-11-16 20:00:00');
    Artisan::call('kanban:send-reminders');
    Mail::assertSent(KanbanActivityReminder::class, function (KanbanActivityReminder $mail) use ($owner, $unchangedAdmin): bool {
        return $mail->ownerAlert
            && $mail->adminNames === [$unchangedAdmin->name]
            && $mail->to[0]['address'] === $owner->email;
    });

    $this->travelTo('2026-11-17 18:00:00');
    Artisan::call('kanban:send-reminders');
    $this->travelTo('2026-11-17 20:00:00');
    Artisan::call('kanban:send-reminders');

    Mail::assertSent(KanbanActivityReminder::class, 5);
    $this->assertDatabaseCount('kanban_notification_logs', 5);
});

test('Kanban reminder emails are sent on Sundays', function () {
    Mail::fake();
    $this->travelTo('2026-11-15 18:00:00');
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    Artisan::call('kanban:send-reminders');
    Mail::assertSent(KanbanActivityReminder::class, function (KanbanActivityReminder $mail) use ($admin): bool {
        return ! $mail->ownerAlert && $mail->hasTo($admin->email);
    });

    $this->travelTo('2026-11-15 20:00:00');
    Artisan::call('kanban:send-reminders');
    Mail::assertSent(KanbanActivityReminder::class, function (KanbanActivityReminder $mail) use ($owner): bool {
        return $mail->ownerAlert && $mail->hasTo($owner->email);
    });
    Mail::assertSentCount(2);
    $this->assertDatabaseCount('kanban_notification_logs', 2);
});
