<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SplCredit extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Default SPL credits per year.
     */
    const YEARLY_CREDITS = 7.00;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'user_id',
        'year',
        'total_credits',
        'credits_used',
        'credits_balance',
    ];

    protected function casts(): array
    {
        return [
            'total_credits' => 'decimal:2',
            'credits_used' => 'decimal:2',
            'credits_balance' => 'decimal:2',
            'year' => 'integer',
        ];
    }

    /**
     * Get the user that owns the SPL credit.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get credits for a specific year.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Scope to get credits for a specific user.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Ensure SPL credit record exists for a user in a given year.
     * Creates one with default 7 credits if not found.
     */
    public static function ensureCreditsExist(int $userId, ?int $year = null): self
    {
        $year = $year ?? now()->year;

        return static::firstOrCreate(
            ['user_id' => $userId, 'year' => $year],
            [
                'total_credits' => self::YEARLY_CREDITS,
                'credits_used' => 0,
                'credits_balance' => self::YEARLY_CREDITS,
            ]
        );
    }

    /**
     * Get the SPL credit balance for a user in a given year.
     */
    public static function getBalance(int $userId, ?int $year = null): float
    {
        $year = $year ?? now()->year;
        $record = static::forUser($userId)->forYear($year)->first();

        return $record ? (float) $record->credits_balance : self::YEARLY_CREDITS;
    }
}
