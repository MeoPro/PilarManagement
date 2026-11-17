<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FactoryPieceworkPunishmentRule extends Model
{
    protected $fillable = [
        'target_salary',
        'action_type',
        'punishment',
        'points_deduction',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_salary' => 'decimal:2',
            'points_deduction' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function actionSummary(): string
    {
        if ($this->action_type === 'points') {
            return 'Pengurangan '.$this->points_deduction.' poin';
        }

        return (string) $this->punishment;
    }
}
