<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CoachingStatusSetting extends Model
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
        'key',
        'value',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }

    /**
     * Default threshold keys and their values.
     */
    public const DEFAULTS = [
        'coaching_done_max_days' => [
            'value' => 15,
            'label' => 'Coaching Done — max days since last coaching',
        ],
        'needs_coaching_max_days' => [
            'value' => 30,
            'label' => 'Needs Coaching — max days since last coaching',
        ],
        'badly_needs_coaching_max_days' => [
            'value' => 45,
            'label' => 'Badly Needs Coaching — max days since last coaching',
        ],
        'no_record_days' => [
            'value' => 60,
            'label' => 'No Record — days without any coaching session',
        ],
        'monthly_session_target' => [
            'value' => 4,
            'label' => 'Monthly session target — required coaching sessions per agent per month',
        ],
    ];

    /**
     * Get all thresholds as a key-value array, with caching.
     *
     * @return array<string, int>
     */
    public static function getThresholds(): array
    {
        return Cache::remember('coaching_status_thresholds', 300, function () {
            $settings = self::pluck('value', 'key')->toArray();

            // Merge with defaults for any missing keys
            $defaults = collect(self::DEFAULTS)->mapWithKeys(
                fn (array $config, string $key) => [$key => $config['value']]
            )->toArray();

            return array_merge($defaults, $settings);
        });
    }

    /**
     * Get a single threshold value by key.
     */
    public static function getThreshold(string $key): int
    {
        $thresholds = self::getThresholds();

        return $thresholds[$key] ?? self::DEFAULTS[$key]['value'] ?? 0;
    }

    /**
     * Clear the cached thresholds.
     */
    public static function clearCache(): void
    {
        Cache::forget('coaching_status_thresholds');
    }

    /**
     * Boot the model — clear cache on save/delete.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::clearCache());
        static::deleted(fn () => self::clearCache());
    }
}
