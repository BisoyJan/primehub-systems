<?php

namespace App\Models;

use Database\Factories\BreakPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BreakPolicy extends Model
{
    /** @use HasFactory<BreakPolicyFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'name',
        'max_breaks',
        'break_duration_minutes',
        'max_lunch',
        'lunch_duration_minutes',
        'grace_period_minutes',
        'allowed_pause_reasons',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'max_breaks' => 'integer',
            'break_duration_minutes' => 'integer',
            'max_lunch' => 'integer',
            'lunch_duration_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'allowed_pause_reasons' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function breakSessions()
    {
        return $this->hasMany(BreakSession::class);
    }
}
