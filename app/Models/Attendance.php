<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'employee_schedule_id',
        'shift_date',
        'scheduled_time_in',
        'scheduled_time_out',
        'actual_time_in',
        'actual_time_out',
        'bio_in_site_id',
        'bio_out_site_id',
        'status',
        'tardy_minutes',
        'undertime_minutes',
        'is_advised',
        'admin_verified',
        'is_cross_site_bio',
        'verification_notes',
        'notes',
    ];

    protected $casts = [
        'shift_date' => 'date:Y-m-d',
        'actual_time_in' => 'datetime',
        'actual_time_out' => 'datetime',
        'is_advised' => 'boolean',
        'admin_verified' => 'boolean',
        'is_cross_site_bio' => 'boolean',
        'tardy_minutes' => 'integer',
        'undertime_minutes' => 'integer',
    ];

    /**
     * Get the user that owns the attendance.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the employee schedule for this attendance.
     */
    public function employeeSchedule(): BelongsTo
    {
        return $this->belongsTo(EmployeeSchedule::class);
    }

    /**
     * Get the site where time in was recorded.
     */
    public function bioInSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'bio_in_site_id');
    }

    /**
     * Get the site where time out was recorded.
     */
    public function bioOutSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'bio_out_site_id');
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('shift_date', [$startDate, $endDate]);
    }

    /**
     * Scope to get records needing verification.
     */
    public function scopeNeedsVerification($query)
    {
        return $query->where(function ($q) {
            $q->whereIn('status', ['failed_bio_in', 'failed_bio_out', 'ncns', 'half_day_absence'])
              ->orWhere('is_cross_site_bio', true);
        })->where('admin_verified', false);
    }

    /**
     * Check if attendance has issues.
     */
    public function hasIssues(): bool
    {
        return in_array($this->status, [
            'tardy',
            'half_day_absence',
            'ncns',
            'undertime',
            'failed_bio_in',
            'failed_bio_out'
        ]);
    }

    /**
     * Get status badge color.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'on_time' => 'green',
            'tardy' => 'yellow',
            'half_day_absence' => 'orange',
            'advised_absence' => 'blue',
            'ncns' => 'red',
            'undertime' => 'orange',
            'failed_bio_in', 'failed_bio_out' => 'purple',
            'present_no_bio' => 'gray',
            default => 'gray',
        };
    }
}
