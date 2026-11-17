<?php

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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_type')->default('Harian');
        });

        DB::table('employees')->update([
            'employee_type' => DB::raw(
                "COALESCE((SELECT work_type FROM employee_attendances WHERE employee_attendances.employee_id = employees.id ORDER BY attendance_date DESC, id DESC LIMIT 1), 'Harian')",
            ),
        ]);

        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->string('attendance_status')->default('Hadir');
            $table->text('status_note')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('corrected_at')->nullable();
            $table->text('correction_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropForeign(['recorded_by_id']);
            $table->dropForeign(['corrected_by_id']);
            $table->dropColumn([
                'attendance_status',
                'status_note',
                'recorded_by_id',
                'corrected_by_id',
                'corrected_at',
                'correction_reason',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employee_type');
        });
    }
};
