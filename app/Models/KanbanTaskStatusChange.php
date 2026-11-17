<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanTaskStatusChange extends Model
{
    protected $fillable = [
        'kanban_task_id',
        'changed_by_id',
        'from_status',
        'to_status',
        'photo_path',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'kanban_task_id' => 'integer',
            'changed_by_id' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(KanbanTask::class, 'kanban_task_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
