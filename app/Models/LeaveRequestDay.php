<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequestDay extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Day status constants.
     */
    const STATUS_PENDING = 'pending';

    const STATUS_SL_CREDITED = 'sl_credited';

    const STATUS_NCNS = 'ncns';

    const STATUS_ADVISED_ABSENCE = 'advised_absence';

    const STATUS_VL_CREDITED = 'vl_credited';

    const STATUS_UPTO = 'upto';

    const STATUS_SPL_CREDITED = 'spl_credited';

    const STATUS_ABSENT = 'absent';

    /**
     * Human-readable labels for day statuses.
     */
    const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_SL_CREDITED => 'SL Credited (Paid)',
        self::STATUS_NCNS => 'NCNS',
        self::STATUS_ADVISED_ABSENCE => 'Advised Absence (UPTO — Unpaid Time Off)',
        self::STATUS_VL_CREDITED => 'VL Credited (Paid)',
        self::STATUS_UPTO => 'UPTO — Unpaid Time Off',
        self::STATUS_SPL_CREDITED => 'SPL Credited (Paid)',
        self::STATUS_ABSENT => 'Absent',
    ];

    /**
     * Statuses that are considered paid (deducted from credits).
     */
    const PAID_STATUSES = [self::STATUS_SL_CREDITED, self::STATUS_VL_CREDITED, self::STATUS_SPL_CREDITED];

    /**
     * Statuses that are considered unpaid.
     */
    const UNPAID_STATUSES = [self::STATUS_NCNS, self::STATUS_ADVISED_ABSENCE, self::STATUS_UPTO, self::STATUS_ABSENT];

    protected $fillable = [
        'leave_request_id',
        'date',
        'day_status',
        'is_half_day',
        'notes',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_half_day' => 'boolean',
            'assigned_at' => 'datetime',
        ];
    }

    /**
     * Get the leave request this day belongs to.
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /**
     * Get the user who assigned the status for this day.
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Scope: only credited (paid) days.
     */
    public function scopeCredited($query)
    {
        return $query->where('day_status', self::STATUS_SL_CREDITED);
    }

    /**
     * Scope: only NCNS days.
     */
    public function scopeNcns($query)
    {
        return $query->where('day_status', self::STATUS_NCNS);
    }

    /**
     * Scope: only Advised Absence (UPTO) days.
     */
    public function scopeAdvisedAbsence($query)
    {
        return $query->where('day_status', self::STATUS_ADVISED_ABSENCE);
    }

    /**
     * Scope: only VL credited (paid) days.
     */
    public function scopeVlCredited($query)
    {
        return $query->where('day_status', self::STATUS_VL_CREDITED);
    }

    /**
     * Scope: only UPTO (VL unpaid) days.
     */
    public function scopeUpto($query)
    {
        return $query->where('day_status', self::STATUS_UPTO);
    }

    /**
     * Scope: only assigned (non-pending) days.
     */
    public function scopeAssigned($query)
    {
        return $query->where('day_status', '!=', self::STATUS_PENDING);
    }

    /**
     * Check if this day is paid (SL Credited).
     */
    public function isPaid(): bool
    {
        return in_array($this->day_status, self::PAID_STATUSES);
    }

    /**
     * Check if this day is unpaid (NCNS, Advised Absence, or UPTO).
     */
    public function isUnpaid(): bool
    {
        return in_array($this->day_status, self::UNPAID_STATUSES);
    }

    /**
     * Scope: only SPL credited (paid) days.
     */
    public function scopeSplCredited($query)
    {
        return $query->where('day_status', self::STATUS_SPL_CREDITED);
    }

    /**
     * Scope: only absent days.
     */
    public function scopeAbsent($query)
    {
        return $query->where('day_status', self::STATUS_ABSENT);
    }

    /**
     * Get the human-readable label for the current status.
     */
    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->day_status] ?? 'Unknown';
    }

    /**
     * Get the credit value for this day.
     * Returns 0.5 for half-day paid, 1.0 for whole-day paid, 0 for unpaid.
     */
    public function getCreditValue(): float
    {
        if (! $this->isPaid()) {
            return 0.0;
        }

        return $this->is_half_day ? 0.5 : 1.0;
    }
}
