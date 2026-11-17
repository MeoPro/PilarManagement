<?php

namespace App\Http\Controllers;

use App\Models\AdminAttendance;
use App\Models\AttendanceSchedule;
use App\Models\KanbanTask;
use App\Models\KanbanTaskStatusChange;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class KanbanTaskController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->isOwner() && $request->input('assignee_id') !== null) {
            abort(403);
        }

        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];

        if ($user->isOwner()) {
            $rules['assignee_id'] = [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('is_admin', true)
                    ->where('role', User::ROLE_ADMIN)
                    ->where('is_active', true)),
            ];
        }

        $validated = $request->validate($rules);
        $assigneeId = $user->isOwner() ? (int) $validated['assignee_id'] : $user->id;
        $assignee = $user->isOwner()
            ? User::query()->findOrFail($assigneeId)
            : $user;
        $localNow = now($assignee->timezone());
        $attendanceSchedule = AttendanceSchedule::current();
        $taskDate = $localNow->toDateString();

        DB::transaction(function () use ($assigneeId, $user, $validated, $taskDate, $localNow, $attendanceSchedule): void {
            if (! $user->isOwner()) {
                $attendance = AdminAttendance::query()
                    ->where('user_id', $user->id)
                    ->whereDate('attendance_date', $taskDate)
                    ->lockForUpdate()
                    ->first();

                abort_if(
                    $attendance?->checked_out_at !== null || ! AdminAttendance::isBoardOpen($localNow),
                    403,
                );

                if ($attendance === null) {
                    $attendanceSession = AdminAttendance::sessionForFirstEntry($localNow, $attendanceSchedule);
                    if ($attendanceSession === null) {
                        $morningWindow = $attendanceSchedule->kanbanFirstEntryWindow('morning');
                        $afternoonWindow = $attendanceSchedule->kanbanFirstEntryWindow('afternoon');
                        $morningStart = str_replace(':', '.', $morningWindow['start']);
                        $morningEnd = str_replace(':', '.', $morningWindow['end']);
                        $afternoonStart = str_replace(':', '.', $afternoonWindow['start']);
                        $afternoonEnd = str_replace(':', '.', $afternoonWindow['end']);
                        throw ValidationException::withMessages([
                            'title' => "Pengisian pertama hanya dibuka pukul {$morningStart}-{$morningEnd} atau {$afternoonStart}-{$afternoonEnd} jika sesi pertama terlewat.",
                        ]);
                    }

                    AdminAttendance::create([
                        'user_id' => $user->id,
                        'attendance_date' => $taskDate,
                        'attendance_session' => $attendanceSession,
                    ]);
                }
            }

            $nextPosition = KanbanTask::query()
                ->where('assignee_id', $assigneeId)
                ->whereDate('task_date', $taskDate)
                ->where('status', 'plan')
                ->max('position');

            KanbanTask::create([
                'assignee_id' => $assigneeId,
                'created_by_id' => $user->id,
                'task_date' => $taskDate,
                'read_at' => $user->isOwner() ? null : now(),
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status' => 'plan',
                'position' => ($nextPosition ?? -1) + 1,
            ]);
        });

        return redirect()
            ->route('kanban.index')
            ->with('success', 'Tugas berhasil ditambahkan.');
    }

    public function updateStatus(Request $request, KanbanTask $kanbanTask): JsonResponse|RedirectResponse
    {
        $this->authorizeTask($request->user(), $kanbanTask);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(KanbanTask::STATUSES))],
        ]);
        if ($kanbanTask->status === $validated['status']) {
            return $this->statusResponse($request, $kanbanTask->fresh(), null);
        }

        $photo = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ])['photo'];
        $photoPath = $photo->store('kanban/status-changes', 'local');
        if (! is_string($photoPath)) {
            throw new RuntimeException('Foto perpindahan Kanban gagal disimpan.');
        }

        try {
            $statusChange = DB::transaction(function () use ($kanbanTask, $validated, $request, $photoPath): ?KanbanTaskStatusChange {
                $task = KanbanTask::query()
                    ->lockForUpdate()
                    ->findOrFail($kanbanTask->id);

                if ($task->status === $validated['status']) {
                    return null;
                }

                $nextPosition = KanbanTask::query()
                    ->where('assignee_id', $task->assignee_id)
                    ->whereDate('task_date', $task->task_date)
                    ->where('status', $validated['status'])
                    ->max('position');
                $fromStatus = $task->status;

                $task->update([
                    'status' => $validated['status'],
                    'position' => ($nextPosition ?? -1) + 1,
                ]);

                return $task->statusChanges()->create([
                    'changed_by_id' => $request->user()->id,
                    'from_status' => $fromStatus,
                    'to_status' => $validated['status'],
                    'photo_path' => $photoPath,
                    'changed_at' => now(),
                ])->load('changedBy');
            });
        } catch (Throwable $exception) {
            if ($photoPath !== null && ! Storage::disk('local')->delete($photoPath)) {
                throw new RuntimeException('Foto perpindahan Kanban gagal dibersihkan setelah perubahan dibatalkan.', previous: $exception);
            }

            throw $exception;
        }

        if ($statusChange === null && $photoPath !== null && ! Storage::disk('local')->delete($photoPath)) {
            throw new RuntimeException('Foto perpindahan Kanban gagal dibersihkan.');
        }

        return $this->statusResponse($request, $kanbanTask->fresh(), $statusChange);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->isOwner(), 403);

        $localNow = now($user->timezone());
        abort_unless(AdminAttendance::isCheckoutWindowOpen($localNow), 403);

        $attendance = AdminAttendance::query()
            ->where('user_id', $user->id)
            ->whereDate('attendance_date', $localNow->toDateString())
            ->firstOrFail();

        if ($attendance->checked_out_at !== null) {
            return redirect()->route('kanban.index')->with('error', 'Kanban hari ini sudah ditutup.');
        }

        $attendance->update(['checked_out_at' => now()]);

        return redirect()->route('kanban.index')->with('success', 'Absen pulang berhasil. Kanban hari ini telah ditutup.');
    }

    public function storeSessionPhotos(Request $request, string $session): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->isOwner(), 403);
        abort_unless(in_array($session, ['morning', 'afternoon'], true), 404);

        $validated = $request->validate([
            'photos' => ['required', 'array', 'size:2'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $localNow = now($user->timezone());

        if (! AdminAttendance::isSessionPhotoWindowOpen($session, $localNow)) {
            return redirect()->route('kanban.index')->with('error', 'Waktu unggah foto sesi Kanban sudah ditutup atau belum dibuka.');
        }

        $attendance = AdminAttendance::query()
            ->where('user_id', $user->id)
            ->whereDate('attendance_date', $localNow->toDateString())
            ->first();

        if ($attendance === null) {
            return redirect()->route('kanban.index')->with('error', 'Buat tugas Kanban terlebih dahulu sebelum mengunggah foto sesi.');
        }

        if ($attendance->checked_out_at !== null) {
            return redirect()->route('kanban.index')->with('error', 'Kanban hari ini sudah ditutup.');
        }

        if ($session === 'morning' && $attendance->attendance_session !== 'morning') {
            return redirect()->route('kanban.index')->with('error', 'Bukti sesi pagi hanya tersedia jika Kanban dimulai pada sesi pagi.');
        }

        if ($attendance->sessionPhotosComplete($session)) {
            return redirect()->route('kanban.index')->with('error', 'Dua foto untuk sesi ini sudah dicatat.');
        }

        $photoPaths = [];

        try {
            foreach ($validated['photos'] as $photo) {
                $photoPath = $photo->store('kanban/session-photos', 'local');
                if (! is_string($photoPath)) {
                    throw new RuntimeException('Foto sesi Kanban gagal disimpan.');
                }

                $photoPaths[] = $photoPath;
            }

            DB::transaction(function () use ($attendance, $photoPaths, $session): void {
                $lockedAttendance = AdminAttendance::query()
                    ->lockForUpdate()
                    ->findOrFail($attendance->id);

                if ($lockedAttendance->checked_out_at !== null
                    || $lockedAttendance->sessionPhotosComplete($session)) {
                    throw ValidationException::withMessages([
                        'photos' => 'Sesi ini sudah ditutup atau foto sesinya sudah dicatat.',
                    ]);
                }

                $lockedAttendance->update([
                    "{$session}_photo_one_path" => $photoPaths[0],
                    "{$session}_photo_two_path" => $photoPaths[1],
                    "{$session}_photos_uploaded_at" => now(),
                ]);
            });
        } catch (Throwable $exception) {
            foreach ($photoPaths as $photoPath) {
                if (! Storage::disk('local')->delete($photoPath)) {
                    throw new RuntimeException(
                        'Foto sesi Kanban gagal dibersihkan setelah unggah dibatalkan.',
                        previous: $exception,
                    );
                }
            }

            throw $exception;
        }

        return redirect()
            ->route('kanban.index')
            ->with('success', 'Dua foto sesi '.($session === 'morning' ? 'pagi' : 'siang').' berhasil dicatat.');
    }

    public function showPhoto(Request $request, KanbanTaskStatusChange $statusChange): BinaryFileResponse
    {
        abort_unless($request->user()->is_admin, 403);
        abort_if($statusChange->photo_path === null, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($statusChange->photo_path), 404);

        return response()->file($disk->path($statusChange->photo_path), [
            'Content-Type' => $disk->mimeType($statusChange->photo_path) ?: 'application/octet-stream',
        ]);
    }

    public function showSessionPhoto(
        Request $request,
        AdminAttendance $attendance,
        string $session,
        int $photoNumber,
    ): BinaryFileResponse {
        abort_unless($request->user()->is_admin, 403);

        $photoPath = $attendance->sessionPhotoPath($session, $photoNumber);
        abort_if($photoPath === null, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($photoPath), 404);

        return response()->file($disk->path($photoPath), [
            'Content-Type' => $disk->mimeType($photoPath) ?: 'application/octet-stream',
        ]);
    }

    public function destroy(Request $request, KanbanTask $kanbanTask): RedirectResponse
    {
        $this->authorizeTask($request->user(), $kanbanTask);

        foreach ($kanbanTask->statusChanges()->whereNotNull('photo_path')->get() as $statusChange) {
            if (! Storage::disk('local')->delete($statusChange->photo_path)) {
                throw new RuntimeException('Foto tugas Kanban gagal dihapus.');
            }
        }

        $kanbanTask->delete();

        return redirect()->route('kanban.index')->with('success', 'Tugas berhasil dihapus.');
    }

    private function statusResponse(
        Request $request,
        KanbanTask $kanbanTask,
        ?KanbanTaskStatusChange $statusChange,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'task' => [
                    'id' => $kanbanTask->id,
                    'status' => $kanbanTask->status,
                    'status_label' => KanbanTask::STATUSES[$kanbanTask->status],
                    'status_change' => $statusChange === null ? null : [
                        'from_status_label' => KanbanTask::STATUSES[$statusChange->from_status],
                        'to_status_label' => KanbanTask::STATUSES[$statusChange->to_status],
                        'changed_by' => $statusChange->changedBy?->name ?? 'Admin',
                        'changed_at' => $statusChange->changed_at
                            ->copy()
                            ->setTimezone($kanbanTask->assignee->timezone())
                            ->format('d/m H:i'),
                        'photo_url' => $statusChange->photo_path
                            ? route('kanban.status-changes.photo', $statusChange)
                            : null,
                    ],
                ],
            ]);
        }

        return redirect()->route('kanban.index')->with('success', 'Status tugas berhasil diperbarui.');
    }

    private function authorizeTask(User $user, KanbanTask $kanbanTask): void
    {
        $isAssignedAdmin = (int) $kanbanTask->assignee_id === $user->id;
        $assigneeTimezone = $user->isOwner()
            ? $kanbanTask->assignee->timezone()
            : $user->timezone();
        $localNow = now($assigneeTimezone);
        $isCurrentTask = $kanbanTask->task_date->toDateString() === $localNow->toDateString();

        abort_unless(($user->isOwner() || $isAssignedAdmin) && $isCurrentTask, 403);

        if (! $user->isOwner()) {
            $isClosed = AdminAttendance::query()
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $kanbanTask->task_date->toDateString())
                ->whereNotNull('checked_out_at')
                ->exists();
            abort_if($isClosed || ! AdminAttendance::isBoardOpen($localNow), 403);
        }
    }
}
