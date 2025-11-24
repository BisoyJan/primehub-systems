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
        'leave_request_id',
        'shift_date',
        'scheduled_time_in',
        'scheduled_time_out',
        'actual_time_in',
        'actual_time_out',
        'bio_in_site_id',
        'bio_out_site_id',
        'status',
        'secondary_status',
        'tardy_minutes',
        'undertime_minutes',
        'overtime_minutes',
        'overtime_approved',
        'overtime_approved_at',
        'overtime_approved_by',
        'is_advised',
        'admin_verified',
        'is_cross_site_bio',
        'verification_notes',
        'notes',
        'warnings',
    ];

    protected $casts = [
        'shift_date' => 'date:Y-m-d',
        'actual_time_in' => 'datetime',
        'actual_time_out' => 'datetime',
        'overtime_approved_at' => 'datetime',
        'is_advised' => 'boolean',
        'admin_verified' => 'boolean',
        'is_cross_site_bio' => 'boolean',
        'overtime_approved' => 'boolean',
        'tardy_minutes' => 'integer',
        'undertime_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'warnings' => 'array',
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
     * Get the leave request associated with this attendance.
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
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
     * Get the user who approved the overtime.
     */
    public function overtimeApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overtime_approved_by');
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
     * Scope to filter records with overtime.
     */
    public function scopeHasOvertime($query)
    {
        return $query->where('overtime_minutes', '>', 0);
    }

    /**
     * Scope to get records needing verification.
     */
    public function scopeNeedsVerification($query)
    {
        return $query->where(function ($q) {
            $q->whereIn('status', ['failed_bio_in', 'failed_bio_out', 'ncns', 'half_day_absence', 'tardy', 'undertime', 'needs_manual_review', 'non_work_day'])
              ->orWhere('is_cross_site_bio', true)
              ->orWhereNotNull('warnings')
              ->orWhere(function ($subQ) {
                  // Include records with unapproved overtime
                  $subQ->where('overtime_minutes', '>', 0)
                       ->where('overtime_approved', false);
              });
        })->where('admin_verified', false);
    }

    /**
     * Scope to get records flagged for manual review.
     */
    public function scopeNeedsManualReview($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'needs_manual_review')
              ->orWhereNotNull('warnings');
        })->where('admin_verified', false);
    }

    /**
     * Scope to get suspicious patterns (extreme scans, few scans far from schedule).
     */
    public function scopeSuspiciousPatterns($query)
    {
        return $query->whereNotNull('warnings')
                     ->where('admin_verified', false)
                     ->orderBy('shift_date', 'desc');
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
            'failed_bio_out',
            'needs_manual_review'
        ]) || !empty($this->warnings);
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
            'on_leave' => 'blue',
            'ncns' => 'red',
            'undertime' => 'orange',
            'failed_bio_in', 'failed_bio_out' => 'purple',
            'needs_manual_review' => 'amber',
            'present_no_bio' => 'gray',
            default => 'gray',
        };
    }
}
