<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class LeaveCreditCarryover extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Maximum credits that can be carried over per year.
     */
    const MAX_CARRYOVER_CREDITS = 4;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'user_id',
        'credits_from_previous_year',
        'carryover_credits',
        'forfeited_credits',
        'from_year',
        'to_year',
        'is_first_regularization',
        'regularization_date',
        'cash_converted',
        'cash_converted_at',
        'processed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'credits_from_previous_year' => 'decimal:2',
            'carryover_credits' => 'decimal:2',
            'forfeited_credits' => 'decimal:2',
            'from_year' => 'integer',
            'to_year' => 'integer',
            'is_first_regularization' => 'boolean',
            'regularization_date' => 'date',
            'cash_converted' => 'boolean',
            'cash_converted_at' => 'date',
        ];
    }

    /**
     * Check if user has ever had a first regularization carryover processed.
     */
    public static function hasFirstRegularization(int $userId): bool
    {
        return static::forUser($userId)
            ->where('is_first_regularization', true)
            ->exists();
    }

    /**
     * Scope for first regularization carryovers.
     */
    public function scopeFirstRegularization($query)
    {
        return $query->where('is_first_regularization', true);
    }

    /**
     * Get the user that owns the carryover.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who processed the carryover.
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Scope to get carryovers for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get carryovers from a specific year.
     */
    public function scopeFromYear($query, int $year)
    {
        return $query->where('from_year', $year);
    }

    /**
     * Scope to get carryovers to a specific year.
     */
    public function scopeToYear($query, int $year)
    {
        return $query->where('to_year', $year);
    }

    /**
     * Scope to get pending cash conversions.
     */
    public function scopePendingCashConversion($query)
    {
        return $query->where('cash_converted', false)
            ->where('carryover_credits', '>', 0);
    }

    /**
     * Scope to get completed cash conversions.
     */
    public function scopeCashConverted($query)
    {
        return $query->where('cash_converted', true);
    }

    /**
     * Get carryover for a specific user and year transition.
     */
    public static function getForUserAndYear(int $userId, int $fromYear): ?self
    {
        return static::forUser($userId)
            ->fromYear($fromYear)
            ->first();
    }

    /**
     * Get total carryover credits for a user going into a specific year.
     */
    public static function getTotalCarryoverToYear(int $userId, int $toYear): float
    {
        return static::forUser($userId)
            ->toYear($toYear)
            ->sum('carryover_credits');
    }
}
