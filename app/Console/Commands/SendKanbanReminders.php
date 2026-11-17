<?php

namespace App\Console\Commands;

use App\Mail\KanbanActivityReminder;
use App\Mail\KanbanAttendanceSummary;
use App\Models\AdminAttendance;
use App\Models\KanbanTaskStatusChange;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendKanbanReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanban:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled Kanban reminders to admins and owners';

    public function handle(): int
    {
        $admins = User::query()
            ->where('is_admin', true)
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        foreach ($admins as $admin) {
            $localNow = now($admin->timezone());
            $date = $localNow->toDateString();

            if ($localNow->hour === 18
                && ! $this->wasNotified($admin, 'admin_reminder', $date)
                && ! $this->hasCheckedOut($admin, $date)
                && ! $this->hasStatusChange($admin, $localNow)) {
                Mail::to($admin)->send(new KanbanActivityReminder(false, [$admin->name], $date));
                $this->recordNotification($admin, 'admin_reminder', $date);
            }

            if ($localNow->hour !== 20 || $this->wasNotified($admin, 'admin_attendance_summary', $date)) {
                continue;
            }

            $attendance = AdminAttendance::query()
                ->where('user_id', $admin->id)
                ->whereDate('attendance_date', $date)
                ->first();

            if ($attendance === null) {
                continue;
            }

            $attendanceCredit = $attendance->attendanceCredit(
                $this->hasAfternoonStatusChange($admin, $localNow),
            );
            if ($attendanceCredit >= 1.0) {
                continue;
            }

            Mail::to($admin)->send(new KanbanAttendanceSummary(
                $admin->name,
                $date,
                $attendanceCredit,
            ));
            $this->recordNotification($admin, 'admin_attendance_summary', $date);
        }

        $owners = User::query()
            ->where('is_admin', true)
            ->where('role', User::ROLE_OWNER)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        foreach ($owners as $owner) {
            $localNow = now($owner->timezone());
            if ($localNow->hour !== 20) {
                continue;
            }

            $date = $localNow->toDateString();
            if ($this->wasNotified($owner, 'owner_alert', $date)) {
                continue;
            }

            $unchangedAdmins = $admins
                ->filter(function (User $admin): bool {
                    $adminNow = now($admin->timezone());
                    $adminDate = $adminNow->toDateString();

                    return ! $this->hasCheckedOut($admin, $adminDate)
                        && ! $this->hasStatusChange($admin, $adminNow);
                })
                ->pluck('name')
                ->all();

            if ($unchangedAdmins === []) {
                continue;
            }

            Mail::to($owner)->send(new KanbanActivityReminder(true, $unchangedAdmins, $date));
            $this->recordNotification($owner, 'owner_alert', $date);
        }

        return self::SUCCESS;
    }

    private function hasStatusChange(User $admin, Carbon $localNow): bool
    {
        $localStart = $localNow->copy()->startOfDay()->setTime(8, 0)->setTimezone(config('app.timezone'));
        $localEnd = $localNow->copy()->startOfDay()->setTime(20, 0)->setTimezone(config('app.timezone'));

        return KanbanTaskStatusChange::query()
            ->where('changed_by_id', $admin->id)
            ->whereBetween('changed_at', [$localStart, $localEnd])
            ->whereHas('task', fn ($query) => $query
                ->where('assignee_id', $admin->id)
                ->whereDate('task_date', $localNow->toDateString()))
            ->exists();
    }

    private function hasAfternoonStatusChange(User $admin, Carbon $localNow): bool
    {
        $localStart = $localNow->copy()->startOfDay()->setTime(12, 30)->setTimezone(config('app.timezone'));
        $localEnd = $localNow->copy()->startOfDay()->setTime(20, 0)->setTimezone(config('app.timezone'));

        return KanbanTaskStatusChange::query()
            ->where('changed_by_id', $admin->id)
            ->where('changed_at', '>=', $localStart)
            ->where('changed_at', '<', $localEnd)
            ->whereHas('task', fn ($query) => $query
                ->where('assignee_id', $admin->id)
                ->whereDate('task_date', $localNow->toDateString()))
            ->exists();
    }

    private function hasCheckedOut(User $admin, string $date): bool
    {
        return AdminAttendance::query()
            ->where('user_id', $admin->id)
            ->whereDate('attendance_date', $date)
            ->whereNotNull('checked_out_at')
            ->exists();
    }

    private function wasNotified(User $recipient, string $type, string $date): bool
    {
        return DB::table('kanban_notification_logs')
            ->where('user_id', $recipient->id)
            ->where('notification_type', $type)
            ->whereDate('notification_date', $date)
            ->exists();
    }

    private function recordNotification(User $recipient, string $type, string $date): void
    {
        DB::table('kanban_notification_logs')->insertOrIgnore([
            'user_id' => $recipient->id,
            'notification_type' => $type,
            'notification_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
