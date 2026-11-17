<?php

namespace Database\Seeders;

use App\Models\KanbanTask;
use App\Models\User;
use Illuminate\Database\Seeder;

class KanbanTaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()
            ->where('is_admin', true)
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->each(function (User $admin): void {
                if ($admin->kanbanTasks()->exists()) {
                    return;
                }

                KanbanTask::factory()
                    ->count(3)
                    ->for($admin, 'assignee')
                    ->for($admin, 'creator')
                    ->sequence(
                        ['status' => 'plan'],
                        ['status' => 'proses'],
                        ['status' => 'selesai'],
                    )
                    ->create();
            });
    }
}
