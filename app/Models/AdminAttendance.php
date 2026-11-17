<?php

namespace App\Models;

use Database\Factories\AdminAttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AdminAttendance extends Model
{
    /** @use HasFactory<AdminAttendanceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'attendance_date',
        'attendance_session',
        'checked_out_at',
        'morning_photo_one_path',
        'morning_photo_two_path',
        'morning_photos_uploaded_at',
        'afternoon_photo_one_path',
        'afternoon_photo_two_path',
        'afternoon_photos_uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'attendance_date' => 'date',
            'checked_out_at' => 'datetime',
            'morning_photos_uploaded_at' => 'datetime',
            'afternoon_photos_uploaded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function sessionForFirstEntry(Carbon $localNow, AttendanceSchedule $schedule): ?string
    {
        $time = $localNow->format('H:i');
        $morning = $schedule->kanbanFirstEntryWindow('morning');

        if ($time >= $morning['start'] && $time < $morning['end']) {
            return 'morning';
        }

        $afternoon = $schedule->kanbanFirstEntryWindow('afternoon');
        if ($time >= $afternoon['start'] && $time < $afternoon['end']) {
            return 'afternoon';
        }

        return null;
    }

    public static function isCheckoutWindowOpen(Carbon $localNow): bool
    {
        $time = $localNow->format('H:i:s');

        return ($time >= '12:00:00' && $time < '13:00:00')
            || ($time >= '17:00:00' && $time < '20:00:00');
    }

    public static function isBoardOpen(Carbon $localNow): bool
    {
        return $localNow->format('H:i:s') < '20:00:00';
    }

    public static function isSessionPhotoWindowOpen(string $session, Carbon $localNow): bool
    {
        $time = $localNow->format('H:i:s');

        return match ($session) {
            'morning' => $time >= '08:00:00' && $time < '10:00:00',
            'afternoon' => $time >= '12:30:00' && $time < '20:00:00',
            default => false,
        };
    }

    public function sessionPhotosComplete(string $session): bool
    {
        return match ($session) {
            'morning' => $this->morning_photos_uploaded_at !== null,
            'afternoon' => $this->afternoon_photos_uploaded_at !== null,
            default => false,
        };
    }

    public function sessionPhotoPath(string $session, int $photoNumber): ?string
    {
        return match ([$session, $photoNumber]) {
            ['morning', 1] => $this->morning_photo_one_path,
            ['morning', 2] => $this->morning_photo_two_path,
            ['afternoon', 1] => $this->afternoon_photo_one_path,
            ['afternoon', 2] => $this->afternoon_photo_two_path,
            default => null,
        };
    }

    public function attendanceCredit(bool $afternoonStatusChanged): float
    {
        $morningComplete = $this->sessionPhotosComplete('morning');
        $afternoonComplete = $this->sessionPhotosComplete('afternoon');

        if (($morningComplete && $afternoonComplete)
            || ($afternoonComplete && $this->attendance_session === 'morning')
            || ($morningComplete && $afternoonStatusChanged)) {
            return 1.0;
        }

        return $morningComplete || $afternoonComplete ? 0.5 : 0.0;
    }
}
