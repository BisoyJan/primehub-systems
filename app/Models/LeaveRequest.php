<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'leave_type',
        'start_date',
        'end_date',
        'days_requested',
        'reason',
        'team_lead_email',
        'campaign_department',
        'medical_cert_submitted',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'credits_deducted',
        'credits_year',
        'attendance_points_at_request',
        'auto_rejected',
        'auto_rejection_reason',
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
        ];
    }

    /**
     * Leave types that deduct from leave credits.
     */
    const CREDITED_LEAVE_TYPES = ['VL', 'SL', 'BL'];

    /**
     * Leave types that don't require credits.
     */
    const NON_CREDITED_LEAVE_TYPES = ['SPL', 'LOA', 'LDV', 'UPTO'];

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
}
