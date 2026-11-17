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
        Schema::table('kanban_tasks', function (Blueprint $table) {
            $table->date('task_date')->nullable()->after('created_by_id');
            $table->index(['task_date', 'assignee_id', 'status', 'position']);
        });

        DB::table('kanban_tasks')
            ->whereNull('task_date')
            ->update(['task_date' => DB::raw('DATE(created_at)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kanban_tasks', function (Blueprint $table) {
            $table->dropIndex(['task_date', 'assignee_id', 'status', 'position']);
            $table->dropColumn('task_date');
        });
    }
};
