<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeSchedule extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'campaign_id',
        'site_id',
        'shift_type',
        'scheduled_time_in',
        'scheduled_time_out',
        'work_days',
        'grace_period_minutes',
        'is_active',
        'effective_date',
        'end_date',
    ];

    protected $casts = [
        'work_days' => 'array',
        'is_active' => 'boolean',
        'effective_date' => 'date',
        'end_date' => 'date',
        'grace_period_minutes' => 'integer',
    ];

    /**
     * Get the user that owns the schedule.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the campaign associated with the schedule.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the site associated with the schedule.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Get the attendances for this schedule.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Scope to get active schedules.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('effective_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * Get the active schedule for a specific date.
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('effective_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $date);
            });
    }

    /**
     * Check if the schedule is for night shift (crosses midnight).
     */
    public function isNightShift(): bool
    {
        return $this->shift_type === 'night_shift' ||
               $this->scheduled_time_in >= '20:00:00';
    }

    /**
     * Check if employee works on a specific day.
     */
    public function worksOnDay(string $dayName): bool
    {
        return in_array(strtolower($dayName), $this->work_days);
    }
}
