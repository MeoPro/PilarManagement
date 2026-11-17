<?php

namespace App\Models;

use Database\Factories\KanbanTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KanbanTask extends Model
{
    public const STATUSES = [
        'plan' => 'Plan',
        'proses' => 'Proses',
        'selesai' => 'Selesai',
        'batal' => 'Batal',
    ];

    /** @use HasFactory<KanbanTaskFactory> */
    use HasFactory;

    protected $fillable = [
        'assignee_id',
        'created_by_id',
        'task_date',
        'read_at',
        'title',
        'description',
        'status',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'assignee_id' => 'integer',
            'created_by_id' => 'integer',
            'task_date' => 'date',
            'position' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(KanbanTaskStatusChange::class)->orderBy('changed_at');
    }
}
