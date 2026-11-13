<?php

namespace App\Models;

use Database\Factories\BreakRetentionPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BreakRetentionPolicy extends Model
{
    /** @use HasFactory<BreakRetentionPolicyFactory> */
    use HasFactory, LogsActivity;

    public const DEFAULT_RETENTION_MONTHS = 12;

    /**
     * Break session types covered by each retention break type.
     *
     * @var array<string, array{label: string, session_types: list<string>}>
     */
    public const BREAK_TYPES = [
        'break' => [
            'label' => 'Breaks',
            'session_types' => ['1st_break', '2nd_break', 'break', 'combined_break'],
        ],
        'lunch' => [
            'label' => 'Lunch',
            'session_types' => ['lunch'],
        ],
        'combined' => [
            'label' => 'Combined (Break + Lunch)',
            'session_types' => ['combined'],
        ],
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'name',
        'description',
        'retention_months',
        'applies_to_type',
        'applies_to_id',
        'break_type',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'retention_months' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'applies_to_id');
    }

    /**
     * Get the applicable retention period for a given context.
     *
     * Resolution order (highest to lowest precedence, priority breaks ties):
     * 1. Site-specific policy for the exact break type
     * 2. Global policy for the exact break type
     * 3. Site-specific policy for 'all' break types
     * 4. Global policy for 'all' break types
     * 5. Default: 12 months
     */
    public static function getRetentionMonths(?int $siteId = null, ?string $breakType = null): int
    {
        $allPolicies = self::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        if ($breakType && $breakType !== 'all') {
            $specificPolicies = $allPolicies->where('break_type', $breakType);

            if ($siteId) {
                $sitePolicy = $specificPolicies
                    ->where('applies_to_type', 'site')
                    ->where('applies_to_id', $siteId)
                    ->first();
                if ($sitePolicy) {
                    return $sitePolicy->retention_months;
                }
            }

            $globalPolicy = $specificPolicies
                ->where('applies_to_type', 'global')
                ->first();
            if ($globalPolicy) {
                return $globalPolicy->retention_months;
            }
        }

        $allTypePolicies = $allPolicies->where('break_type', 'all');

        if ($siteId) {
            $siteAllPolicy = $allTypePolicies
                ->where('applies_to_type', 'site')
                ->where('applies_to_id', $siteId)
                ->first();
            if ($siteAllPolicy) {
                return $siteAllPolicy->retention_months;
            }
        }

        $globalAllPolicy = $allTypePolicies
            ->where('applies_to_type', 'global')
            ->first();
        if ($globalAllPolicy) {
            return $globalAllPolicy->retention_months;
        }

        return self::DEFAULT_RETENTION_MONTHS;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
