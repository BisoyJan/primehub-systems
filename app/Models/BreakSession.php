<?php

namespace App\Models;

use Database\Factories\BreakSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BreakSession extends Model
{
    /** @use HasFactory<BreakSessionFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'session_id',
        'user_id',
        'station',
        'break_policy_id',
        'type',
        'combined_break_count',
        'status',
        'ended_by',
        'duration_seconds',
        'started_at',
        'ended_at',
        'remaining_seconds',
        'overage_seconds',
        'overbreak_notified_at',
        'total_paused_seconds',
        'last_pause_reason',
        'shift_date',
    ];

    protected function casts(): array
    {
        return [
            'combined_break_count' => 'integer',
            'duration_seconds' => 'integer',
            'remaining_seconds' => 'integer',
            'overage_seconds' => 'integer',
            'overbreak_notified_at' => 'datetime',
            'total_paused_seconds' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'shift_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function breakPolicy(): BelongsTo
    {
        return $this->belongsTo(BreakPolicy::class);
    }

    public function breakEvents(): HasMany
    {
        return $this->hasMany(BreakEvent::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('shift_date', $date);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'paused']);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('session_id', 'like', "%{$search}%")
                ->orWhere('station', 'like', "%{$search}%")
                ->orWhereHas('user', fn (Builder $uq) => $uq->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"));
        });
    }
}
