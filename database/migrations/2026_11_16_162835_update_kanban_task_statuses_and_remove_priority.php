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
        DB::table('kanban_tasks')->where('status', 'todo')->update(['status' => 'plan']);
        DB::table('kanban_tasks')
            ->whereIn('status', ['in_progress', 'in_review'])
            ->update(['status' => 'proses']);
        DB::table('kanban_tasks')->where('status', 'done')->update(['status' => 'selesai']);

        Schema::table('kanban_tasks', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kanban_tasks', function (Blueprint $table): void {
            $table->string('priority')->default('medium');
        });

        DB::table('kanban_tasks')->where('status', 'plan')->update(['status' => 'todo']);
        DB::table('kanban_tasks')
            ->whereIn('status', ['proses', 'batal'])
            ->update(['status' => 'in_progress']);
        DB::table('kanban_tasks')->where('status', 'selesai')->update(['status' => 'done']);
    }
};
