<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class LeaveCredit extends Model
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
        'credits_earned',
        'credits_used',
        'credits_balance',
        'year',
        'month',
        'accrued_at',
    ];

    protected function casts(): array
    {
        return [
            'credits_earned' => 'decimal:2',
            'credits_used' => 'decimal:2',
            'credits_balance' => 'decimal:2',
            'year' => 'integer',
            'month' => 'integer',
            'accrued_at' => 'date',
        ];
    }

    /**
     * Get the user that owns the leave credit.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get credits for a specific year.
     */
    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Scope to get credits for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get credits for a specific month and year.
     */
    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * Get total balance for a user in a specific year.
     * Balance = Total Earned - Total Used
     */
    public static function getTotalBalance(int $userId, int $year): float
    {
        $earned = static::getTotalEarned($userId, $year);
        $used = static::getTotalUsed($userId, $year);
        return $earned - $used;
    }

    /**
     * Get total earned credits for a user in a specific year.
     * Excludes carryover (month 0) since that's tracked separately in LeaveCreditCarryover.
     */
    public static function getTotalEarned(int $userId, int $year): float
    {
        return static::forUser($userId)
            ->forYear($year)
            ->where('month', '>', 0) // Exclude carryover (month 0)
            ->sum('credits_earned');
    }

    /**
     * Get total used credits for a user in a specific year.
     * Includes all months including carryover (month 0).
     */
    public static function getTotalUsed(int $userId, int $year): float
    {
        return static::forUser($userId)
            ->forYear($year)
            ->sum('credits_used');
    }
}
