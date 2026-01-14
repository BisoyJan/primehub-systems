<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class LeaveRequest extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'user_id',
        'leave_type',
        'start_date',
        'end_date',
        'days_requested',
        'reason',
        'campaign_department',
        'medical_cert_submitted',
        'medical_cert_path',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'credits_deducted',
        'credits_year',
        'attendance_points_at_request',
        'auto_rejected',
        'auto_rejection_reason',
        // Dual approval fields
        'admin_approved_by',
        'admin_approved_at',
        'admin_review_notes',
        'hr_approved_by',
        'hr_approved_at',
        'hr_review_notes',
        // Team Lead approval fields (for Agent leave requests)
        'requires_tl_approval',
        'tl_approved_by',
        'tl_approved_at',
        'tl_review_notes',
        'tl_rejected',
        // Short notice override fields (Admin/Super Admin can bypass 2-week notice)
        'short_notice_override',
        'short_notice_override_by',
        'short_notice_override_at',
        // Date modification tracking (when approved leave dates are changed)
        'original_start_date',
        'original_end_date',
        'date_modified_by',
        'date_modified_at',
        'date_modification_reason',
        // Auto-cancellation fields (when employee reports to work during leave)
        'auto_cancelled',
        'auto_cancelled_reason',
        'auto_cancelled_at',
        // Cancellation tracking (for admin cancellation of approved leaves)
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        // Partial denial fields (when reviewer approves some dates but denies others)
        'has_partial_denial',
        'approved_days',
        'sl_credits_applied',
        'sl_no_credit_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_requested' => 'decimal:2',
            'medical_cert_submitted' => 'boolean',
            'reviewed_at' => 'datetime',
            'credits_deducted' => 'decimal:2',
            'credits_year' => 'integer',
            'attendance_points_at_request' => 'decimal:2',
            'auto_rejected' => 'boolean',
            'admin_approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
            'requires_tl_approval' => 'boolean',
            // Short notice override
            'short_notice_override' => 'boolean',
            'short_notice_override_at' => 'datetime',
            // Date modification tracking
            'original_start_date' => 'date',
            'original_end_date' => 'date',
            'date_modified_at' => 'datetime',
            // Auto-cancellation
            'auto_cancelled' => 'boolean',
            'auto_cancelled_at' => 'datetime',
            // Cancellation tracking
            'cancelled_at' => 'datetime',
            'tl_approved_at' => 'datetime',
            'tl_rejected' => 'boolean',
            // Partial denial
            'has_partial_denial' => 'boolean',
            'approved_days' => 'decimal:2',
            'sl_credits_applied' => 'boolean',
        ];
    }

    /**
     * Leave types that deduct from leave credits.
     */
    const CREDITED_LEAVE_TYPES = ['VL', 'SL'];

    /**
     * Leave types that don't require credits.
     * BL = Bereavement Leave, ML = Maternity Leave
     */
    const NON_CREDITED_LEAVE_TYPES = ['BL', 'SPL', 'LOA', 'LDV', 'UPTO', 'ML'];

    /**
     * Get the user who submitted the leave request.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who reviewed the request.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the admin who approved the request.
     */
    public function adminApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_approved_by');
    }

    /**
     * Get the HR who approved the request.
     */
    public function hrApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    /**
     * Get the Team Lead who approved the request.
     */
    public function tlApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tl_approved_by');
    }

    /**
     * Get the attendance records associated with this leave request.
     */
    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get the user who overrode the short notice requirement.
     */
    public function shortNoticeOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'short_notice_override_by');
    }

    /**
     * Get the user who modified the leave dates.
     */
    public function dateModifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'date_modified_by');
    }

    /**
     * Get the user who cancelled the leave request.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Alias for cancelledBy for frontend compatibility.
     */
    public function canceller(): BelongsTo
    {
        return $this->cancelledBy();
    }

    /**
     * Alias for shortNoticeOverrideBy for frontend compatibility.
     */
    public function shortNoticeOverrider(): BelongsTo
    {
        return $this->shortNoticeOverrideBy();
    }

    /**
     * Get the denied dates for partial denial requests.
     */
    public function deniedDates()
    {
        return $this->hasMany(LeaveRequestDeniedDate::class);
    }

    /**
     * Check if this leave type requires leave credits.
     */
    public function requiresCredits(): bool
    {
        return in_array($this->leave_type, self::CREDITED_LEAVE_TYPES);
    }

    /**
     * Check if this leave type requires attendance points validation.
     */
    public function requiresAttendancePointsCheck(): bool
    {
        // VL and BL (which is treated as VL) require ≤6 points
        return in_array($this->leave_type, ['VL', 'BL']);
    }

    /**
     * Check if this leave type requires 2-week advance notice.
     */
    public function requiresTwoWeekNotice(): bool
    {
        // Only VL and BL require 2-week notice (SL is unpredictable)
        return in_array($this->leave_type, ['VL', 'BL']);
    }

    /**
     * Check if this leave type requires 30-day absence check.
     */
    public function requiresThirtyDayAbsenceCheck(): bool
    {
        // Only VL and BL require no absence in last 30 days
        return in_array($this->leave_type, ['VL', 'BL']);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by leave type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('leave_type', $type);
    }

    /**
     * Scope to filter by user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_date', [$startDate, $endDate])
            ->orWhereBetween('end_date', [$startDate, $endDate])
            ->orWhere(function ($q) use ($startDate, $endDate) {
                $q->where('start_date', '<=', $startDate)
                    ->where('end_date', '>=', $endDate);
            });
    }

    /**
     * Scope for pending requests.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for approved requests.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Check if request is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if request is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if request can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return $this->isPending() || ($this->isApproved() && $this->start_date > now());
    }

    /**
     * Check if admin has approved.
     */
    public function isAdminApproved(): bool
    {
        return $this->admin_approved_by !== null;
    }

    /**
     * Check if HR has approved.
     */
    public function isHrApproved(): bool
    {
        return $this->hr_approved_by !== null;
    }

    /**
     * Check if both Admin and HR have approved (fully approved).
     */
    public function isFullyApproved(): bool
    {
        return $this->isAdminApproved() && $this->isHrApproved();
    }

    /**
     * Check if request has partial approval (only one of Admin/HR approved).
     */
    public function hasPartialApproval(): bool
    {
        return ($this->isAdminApproved() || $this->isHrApproved()) && !$this->isFullyApproved();
    }

    /**
     * Get the approval status text for display.
     */
    public function getApprovalStatusText(): string
    {
        if ($this->status !== 'pending') {
            return ucfirst($this->status);
        }

        // Check Team Lead approval first for agent requests
        if ($this->requires_tl_approval) {
            if ($this->tl_rejected) {
                return 'Rejected by Team Lead';
            }
            if (!$this->isTlApproved()) {
                return 'Pending Team Lead Approval';
            }
        }

        if ($this->isFullyApproved()) {
            return 'Fully Approved';
        }

        if ($this->isAdminApproved()) {
            return 'Pending HR Approval';
        }

        if ($this->isHrApproved()) {
            return 'Pending Admin Approval';
        }

        return 'Pending Both Approvals';
    }

    /**
     * Check if Team Lead has approved.
     */
    public function isTlApproved(): bool
    {
        return $this->tl_approved_by !== null;
    }

    /**
     * Check if Team Lead has rejected.
     */
    public function isTlRejected(): bool
    {
        return $this->tl_rejected === true;
    }

    /**
     * Check if this request requires Team Lead approval.
     */
    public function requiresTlApproval(): bool
    {
        return $this->requires_tl_approval === true;
    }

    /**
     * Check if request is ready for Admin/HR approval (TL approved or not required).
     */
    public function isReadyForAdminHrApproval(): bool
    {
        if (!$this->requiresTlApproval()) {
            return true;
        }

        return $this->isTlApproved() && !$this->isTlRejected();
    }

    /**
     * Check if the request is fully approved including TL approval if required.
     */
    public function isCompletelyApproved(): bool
    {
        if ($this->requiresTlApproval() && !$this->isTlApproved()) {
            return false;
        }

        return $this->isFullyApproved();
    }
}
