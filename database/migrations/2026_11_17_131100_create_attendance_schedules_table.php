<?php

use App\Models\AttendanceSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_schedules', function (Blueprint $table) {
            $table->id();
            $table->time('employee_morning_start')->default('00:00:00');
            $table->time('employee_morning_end')->default('09:00:00');
            $table->time('employee_afternoon_start')->default('12:00:00');
            $table->time('employee_afternoon_end')->default('13:30:00');
            $table->time('kanban_morning_start')->default('08:00:00');
            $table->time('kanban_morning_end')->default('09:00:00');
            $table->time('kanban_afternoon_start')->default('12:30:00');
            $table->time('kanban_afternoon_end')->default('15:00:00');
            $table->timestamps();
        });

        DB::table('attendance_schedules')->insert([
            'id' => 1,
            ...array_map(
                static fn (string $time): string => $time.':00',
                AttendanceSchedule::DEFAULT_TIMES,
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_schedules');
    }
};
