<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kanban_task_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kanban_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->string('photo_path')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();

            $table->index(['kanban_task_id', 'changed_at']);
            $table->index(['changed_by_id', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kanban_task_status_changes');
    }
};
