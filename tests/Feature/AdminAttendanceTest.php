<?php

use App\Models\AdminAttendance;
use App\Models\KanbanTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('counts completed Kanban session proof and shows the monthly percentage to the owner', function () {
    Storage::fake('local');
    $this->travelTo('2026-11-16 08:30:00');
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($owner)
        ->post(route('kanban.tasks.store'), [
            'assignee_id' => $admin->id,
            'title' => 'Tugas dari owner',
        ])
        ->assertRedirect(route('kanban.index'));

    expect(AdminAttendance::count())->toBe(0);

    $this->actingAs($admin)
        ->post(route('kanban.tasks.store'), [
            'title' => 'Catatan harian',
        ])
        ->assertRedirect(route('kanban.index'));
    $this->post(route('kanban.attendance.session-photos.store', 'morning'), [
        'photos' => [
            UploadedFile::fake()->create('morning-1.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('morning-2.jpg', 100, 'image/jpeg'),
        ],
    ])->assertRedirect(route('kanban.index'));

    $this->post(route('kanban.tasks.store'), [
        'title' => 'Catatan tambahan',
    ])->assertRedirect(route('kanban.index'));

    $this->travelTo('2026-11-16 13:00:00');
    $this->post(route('kanban.attendance.session-photos.store', 'afternoon'), [
        'photos' => [
            UploadedFile::fake()->create('afternoon-1.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('afternoon-2.jpg', 100, 'image/jpeg'),
        ],
    ])->assertRedirect(route('kanban.index'));

    expect(AdminAttendance::count())->toBe(1)
        ->and(KanbanTask::where('created_by_id', $admin->id)->count())->toBe(2);

    $this->actingAs($owner)
        ->get(route('kanban.index', ['month' => '2026-11']))
        ->assertSeeText($admin->name)
        ->assertSeeText('1,0/1')
        ->assertSeeText('100,0%')
        ->assertSeeText('Lengkap · 2/2')
        ->assertSeeText('1,0 hari');

    $this->actingAs($admin)
        ->get(route('kanban.index', ['month' => '2026-11']))
        ->assertDontSeeText('Kehadiran Admin');
});
