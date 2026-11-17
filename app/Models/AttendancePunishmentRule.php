<?php

namespace App\Models;

use Database\Factories\AttendancePunishmentRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendancePunishmentRule extends Model
{
    /** @use HasFactory<AttendancePunishmentRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'applies_to',
        'attendance_below',
        'punishment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'attendance_below' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
