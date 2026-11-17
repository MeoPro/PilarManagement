<?php

namespace App\Http\Controllers;

use App\Models\AdminAttendance;
use App\Models\AttendanceSchedule;
use App\Models\KanbanTask;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KanbanController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $localNow = now($user->timezone());
        $attendanceSchedule = AttendanceSchedule::current();
        $localToday = $localNow->copy()->startOfDay();
        $validated = $request->validate([
            'task_date' => ['nullable', 'date_format:Y-m-d'],
            'admin_id' => ['nullable', 'integer'],
        ]);
        $taskDate = $user->isOwner()
            ? ($validated['task_date'] ?? $localToday->toDateString())
            : $localToday->toDateString();
        abort_if($taskDate > $localToday->toDateString(), 422, 'Tanggal tugas belum tiba.');

        if (! $user->isOwner()) {
            $user->kanbanTasks()
                ->whereNull('read_at')
                ->whereDate('task_date', $taskDate)
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $attendance = $user->isOwner()
            ? null
            : AdminAttendance::query()
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $localToday->toDateString())
                ->first();

        $admins = $user->isOwner()
            ? User::query()
                ->where('is_admin', true)
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->whereDate('created_at', '<=', $localToday->toDateString())
                ->orderBy('name')
                ->get(['id', 'name', 'business_unit', 'created_at'])
            : collect();
        $selectedAdminId = $user->isOwner() ? ($validated['admin_id'] ?? null) : null;
        if ($selectedAdminId !== null) {
            $selectedAdminId = (int) $selectedAdminId;
            abort_unless($admins->contains('id', $selectedAdminId), 404);
        }

        $tasks = KanbanTask::query()
            ->with(['assignee:id,name,business_unit', 'statusChanges.changedBy:id,name'])
            ->when(
                $user->isOwner() && $selectedAdminId !== null,
                fn ($query) => $query->where('assignee_id', $selectedAdminId),
            )
            ->whereDate('task_date', $taskDate)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('status');
        $isBoardClosed = ! $user->isOwner()
            && ($attendance?->checked_out_at !== null || ! AdminAttendance::isBoardOpen($localNow));
        $canCreateTask = $taskDate === $localToday->toDateString()
            && ($user->isOwner()
                || (! $isBoardClosed
                    && ($attendance !== null || AdminAttendance::sessionForFirstEntry($localNow, $attendanceSchedule) !== null)));
        $canCheckout = ! $user->isOwner()
            && $taskDate === $localToday->toDateString()
            && $attendance !== null
            && ! $isBoardClosed
            && AdminAttendance::isCheckoutWindowOpen($localNow);
        $canUploadSessionPhotos = [
            'morning' => ! $user->isOwner()
                && $attendance?->attendance_session === 'morning'
                && ! $isBoardClosed
                && AdminAttendance::isSessionPhotoWindowOpen('morning', $localNow),
            'afternoon' => ! $user->isOwner()
                && $attendance !== null
                && ! $isBoardClosed
                && AdminAttendance::isSessionPhotoWindowOpen('afternoon', $localNow),
        ];

        return view('kanban.index', [
            'admins' => $admins,
            'columns' => KanbanTask::STATUSES,
            'selectedAdminId' => $selectedAdminId,
            'tasks' => $tasks,
            'attendance' => $attendance,
            'isBoardClosed' => $isBoardClosed,
            'canCreateTask' => $canCreateTask,
            'canCheckout' => $canCheckout,
            'canUploadSessionPhotos' => $canUploadSessionPhotos,
            'taskDate' => $taskDate,
            'isCurrentTaskDate' => $taskDate === $localToday->toDateString(),
            'localToday' => $localToday,
            'localNow' => $localNow,
            'firstEntryWindows' => [
                'morning' => $attendanceSchedule->kanbanFirstEntryWindow('morning'),
                'afternoon' => $attendanceSchedule->kanbanFirstEntryWindow('afternoon'),
            ],
        ]);
    }
}
