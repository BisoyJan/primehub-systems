<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CoachingExclusion extends Model
{
    use HasFactory, LogsActivity;

    public const REASON_NEW_HIRE = 'New Hire';

    public const REASON_LONG_LEAVE = 'Long Leave';

    public const REASON_RESIGNED_NOTICE = 'Resigned/Notice';

    public const REASON_ON_PIP = 'On PIP';

    public const REASON_ROLE_CHANGE = 'Role Change';

    public const REASON_OTHER = 'Other';

    public const REASONS = [
        self::REASON_NEW_HIRE,
        self::REASON_LONG_LEAVE,
        self::REASON_RESIGNED_NOTICE,
        self::REASON_ON_PIP,
        self::REASON_ROLE_CHANGE,
        self::REASON_OTHER,
    ];

    protected $fillable = [
        'user_id',
        'reason',
        'notes',
        'excluded_by',
        'excluded_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'revoke_notes',
    ];

    protected function casts(): array
    {
        return [
            'excluded_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Active = not revoked AND not expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
