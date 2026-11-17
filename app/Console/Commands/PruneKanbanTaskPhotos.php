<?php

namespace App\Console\Commands;

use App\Models\AdminAttendance;
use App\Models\KanbanTaskStatusChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PruneKanbanTaskPhotos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanban:prune-photos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete Kanban photos older than one month';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $deletedPhotos = 0;
        $cutoff = now()->subMonthNoOverflow();

        KanbanTaskStatusChange::query()
            ->whereNotNull('photo_path')
            ->where('changed_at', '<=', now()->subMonthNoOverflow())
            ->orderBy('id')
            ->chunkById(100, function ($statusChanges) use ($disk, &$deletedPhotos): void {
                foreach ($statusChanges as $statusChange) {
                    if (! $disk->delete($statusChange->photo_path)) {
                        throw new RuntimeException('Foto Kanban gagal dihapus: '.$statusChange->photo_path);
                    }

                    $statusChange->update(['photo_path' => null]);
                    $deletedPhotos++;
                }
            });

        AdminAttendance::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('morning_photos_uploaded_at', '<=', $cutoff)
                    ->orWhere('afternoon_photos_uploaded_at', '<=', $cutoff);
            })
            ->where(function ($query): void {
                $query->whereNotNull('morning_photo_one_path')
                    ->orWhereNotNull('morning_photo_two_path')
                    ->orWhereNotNull('afternoon_photo_one_path')
                    ->orWhereNotNull('afternoon_photo_two_path');
            })
            ->orderBy('id')
            ->chunkById(100, function ($attendances) use ($disk, &$deletedPhotos): void {
                foreach ($attendances as $attendance) {
                    foreach ([
                        'morning_photo_one_path',
                        'morning_photo_two_path',
                        'afternoon_photo_one_path',
                        'afternoon_photo_two_path',
                    ] as $photoColumn) {
                        $photoPath = $attendance->{$photoColumn};
                        if ($photoPath === null) {
                            continue;
                        }

                        if (! $disk->delete($photoPath)) {
                            throw new RuntimeException('Foto Kanban gagal dihapus: '.$photoPath);
                        }

                        $attendance->update([$photoColumn => null]);
                        $deletedPhotos++;
                    }
                }
            });

        $this->info("Deleted {$deletedPhotos} Kanban photo(s).");

        return self::SUCCESS;
    }
}
