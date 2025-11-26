<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class BiometricRecord extends Model
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
        'attendance_upload_id',
        'site_id',
        'employee_name',
        'datetime',
        'record_date',
        'record_time',
    ];

    protected $casts = [
        'datetime' => 'datetime',
        'record_date' => 'date',
    ];

    /**
     * Get the user that owns the biometric record.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attendance upload that created this record.
     */
    public function attendanceUpload(): BelongsTo
    {
        return $this->belongsTo(AttendanceUpload::class);
    }

    /**
     * Get the site where this biometric scan occurred.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Scope to get records for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get records for a specific date.
     */
    public function scopeForDate($query, Carbon $date)
    {
        return $query->where('record_date', $date->format('Y-m-d'));
    }

    /**
     * Scope to get records within a date range.
     */
    public function scopeDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('record_date', [
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        ]);
    }

    /**
     * Scope to get records older than specified months.
     */
    public function scopeOlderThan($query, int $months)
    {
        $cutoffDate = Carbon::now()->subMonths($months);
        return $query->where('record_date', '<', $cutoffDate->format('Y-m-d'));
    }

    /**
     * Scope to get records for a specific site.
     */
    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    /**
     * Get records ordered by datetime.
     */
    public function scopeOrderedByTime($query)
    {
        return $query->orderBy('datetime');
    }
}
