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
        Schema::table('piecework_items', function (Blueprint $table) {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('employee_attendance_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index(['employee_id', 'created_at']);
            $table->dropForeign(['employee_attendance_id']);
        });

        DB::table('piecework_items')
            ->join('employee_attendances', 'piecework_items.employee_attendance_id', '=', 'employee_attendances.id')
            ->select(['piecework_items.id as id', 'employee_attendances.employee_id'])
            ->orderBy('piecework_items.id')
            ->chunkById(500, function ($pieceworkItems): void {
                foreach ($pieceworkItems as $pieceworkItem) {
                    DB::table('piecework_items')
                        ->where('id', $pieceworkItem->id)
                        ->update(['employee_id' => $pieceworkItem->employee_id]);
                }
            }, 'piecework_items.id', 'id');

        Schema::table('piecework_items', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
            $table->unsignedBigInteger('employee_attendance_id')->nullable()->change();
            $table->foreign('employee_attendance_id')
                ->references('id')
                ->on('employee_attendances')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('piecework_items')->whereNull('employee_attendance_id')->exists()) {
            throw new RuntimeException('Cannot roll back employee-linked piecework while standalone production records exist.');
        }

        Schema::table('piecework_items', function (Blueprint $table) {
            $table->dropForeign(['employee_attendance_id']);
            $table->unsignedBigInteger('employee_attendance_id')->nullable(false)->change();
            $table->foreign('employee_attendance_id')
                ->references('id')
                ->on('employee_attendances')
                ->cascadeOnDelete();
            $table->dropIndex(['employee_id', 'created_at']);
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
