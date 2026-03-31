<?php

namespace App\Models;

use Database\Factories\BreakEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BreakEvent extends Model
{
    /** @use HasFactory<BreakEventFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'break_session_id',
        'action',
        'remaining_seconds',
        'overage_seconds',
        'reason',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'remaining_seconds' => 'integer',
            'overage_seconds' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function breakSession(): BelongsTo
    {
        return $this->belongsTo(BreakSession::class);
    }
}
