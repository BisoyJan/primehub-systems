<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePoint extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'attendance_id',
        'shift_date',
        'point_type',
        'points',
        'status',
        'is_advised',
        'notes',
        'is_excused',
        'excused_by',
        'excused_at',
        'excuse_reason',
        'expires_at',
        'expiration_type',
        'is_expired',
        'expired_at',
        'violation_details',
        'tardy_minutes',
        'undertime_minutes',
        'eligible_for_gbro',
        'gbro_applied_at',
        'gbro_batch_id',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'points' => 'decimal:2',
        'is_advised' => 'boolean',
        'is_excused' => 'boolean',
        'excused_at' => 'datetime',
        'expires_at' => 'date',
        'is_expired' => 'boolean',
        'expired_at' => 'date',
        'eligible_for_gbro' => 'boolean',
        'gbro_applied_at' => 'date',
    ];

    /**
     * Point values for each type
     */
    public const POINT_VALUES = [
        'whole_day_absence' => 1.00,
        'half_day_absence' => 0.50,
        'undertime' => 0.25,
        'tardy' => 0.25,
    ];

    /**
     * Get the user that owns the attendance point.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attendance record.
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * Get the user who excused this point.
     */
    public function excusedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excused_by');
    }

    /**
     * Scope to filter active (non-excused) points.
     */
    public function scopeActive($query)
    {
        return $query->where('is_excused', false);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('shift_date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by point type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('point_type', $type);
    }

    /**
     * Get formatted point type name.
     */
    public function getFormattedTypeAttribute(): string
    {
        return match ($this->point_type) {
            'whole_day_absence' => 'Whole Day Absence (NCNS)',
            'half_day_absence' => 'Half-Day Absence',
            'undertime' => 'Undertime',
            'tardy' => 'Tardy',
            default => $this->point_type,
        };
    }

    /**
     * Get point type color for badge.
     */
    public function getTypeColorAttribute(): string
    {
        return match ($this->point_type) {
            'whole_day_absence' => 'red',
            'half_day_absence' => 'orange',
            'undertime' => 'yellow',
            'tardy' => 'yellow',
            default => 'gray',
        };
    }

    /**
     * Scope to filter non-expired active points.
     */
    public function scopeNonExpired($query)
    {
        return $query->where('is_expired', false);
    }

    /**
     * Scope to filter expired points.
     */
    public function scopeExpired($query)
    {
        return $query->where('is_expired', true);
    }

    /**
     * Scope to filter points eligible for GBRO.
     */
    public function scopeEligibleForGbro($query)
    {
        return $query->where('eligible_for_gbro', true)
            ->whereNull('gbro_applied_at')
            ->where('is_expired', false)
            ->where('is_excused', false);
    }

    /**
     * Check if this point is NCNS/FTN (1-year expiration).
     */
    public function isNcnsOrFtn(): bool
    {
        return $this->point_type === 'whole_day_absence' && !$this->is_advised;
    }

    /**
     * Calculate expiration date based on point type and rules.
     */
    public function calculateExpirationDate(): \Carbon\Carbon
    {
        $shiftDate = \Carbon\Carbon::parse($this->shift_date);

        if ($this->isNcnsOrFtn()) {
            // NCNS/FTN: 1 year expiration
            return $shiftDate->addYear();
        }

        // Standard violations: 6 months expiration
        return $shiftDate->addMonths(6);
    }

    /**
     * Set expiration date when point is created.
     */
    public function setExpirationDate(): void
    {
        $this->attributes['expires_at'] = $this->calculateExpirationDate()->format('Y-m-d');
        $this->expiration_type = $this->isNcnsOrFtn() ? 'none' : 'sro';
        $this->save();
    }

    /**
     * Check if point should be expired now.
     */
    public function shouldExpire(): bool
    {
        if ($this->is_expired || $this->is_excused) {
            return false;
        }

        if (!$this->expires_at) {
            return false;
        }

        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Mark point as expired.
     */
    public function markAsExpired(string $type = 'sro'): void
    {
        $this->update([
            'is_expired' => true,
            'expired_at' => now(),
            'expiration_type' => $type,
        ]);
    }

    /**
     * Get violation details for display.
     */
    public function getViolationDetailsAttribute(): ?string
    {
        if ($this->attributes['violation_details'] ?? null) {
            return $this->attributes['violation_details'];
        }

        // Generate violation details based on type
        return match ($this->point_type) {
            'whole_day_absence' => $this->is_advised
                ? 'Advised absence (Failed to Notify - FTN)'
                : 'No Call, No Show (NCNS) - Did not report for work without prior notice',
            'half_day_absence' => 'Late arrival exceeding 15 minutes from scheduled time',
            'tardy' => sprintf('Late arrival by %d minutes', $this->tardy_minutes ?? 0),
            'undertime' => sprintf('Early departure by %d minutes before scheduled end time', $this->undertime_minutes ?? 0),
            default => 'Attendance violation',
        };
    }

    /**
     * Get expiration status message.
     */
    public function getExpirationStatusAttribute(): string
    {
        if ($this->is_expired) {
            $type = match ($this->expiration_type) {
                'gbro' => 'GBRO (Good Behavior)',
                'sro' => 'SRO (Standard)',
                default => 'Expired',
            };
            return "Expired via {$type}";
        }

        if ($this->expires_at) {
            $daysUntilExpiration = now()->diffInDays($this->expires_at, false);
            if ($daysUntilExpiration < 0) {
                return 'Pending expiration';
            }
            return "Expires in {$daysUntilExpiration} days";
        }

        return 'No expiration set';
    }
}

