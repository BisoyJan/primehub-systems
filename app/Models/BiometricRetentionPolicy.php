<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class BiometricRetentionPolicy extends Model
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
        'name',
        'description',
        'retention_months',
        'applies_to_type',
        'applies_to_id',
        'record_type',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'retention_months' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the site this policy applies to
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'applies_to_id');
    }

    /**
     * Get the applicable retention period for a given context
     *
     * Resolution order (highest to lowest priority):
     * 1. Site-specific policy for the exact record type
     * 2. Global policy for the exact record type
     * 3. Site-specific policy for 'all' record types
     * 4. Global policy for 'all' record types
     * 5. Default: 3 months for biometric_record, 12 months for attendance_point
     */
    public static function getRetentionMonths(?int $siteId = null, ?string $recordType = null): int
    {
        // Get all active policies, sorted by priority (highest first)
        $allPolicies = self::where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        // Try to find a matching policy for the specific record type first
        if ($recordType && $recordType !== 'all') {
            $specificPolicies = $allPolicies->where('record_type', $recordType);

            // Check site-specific policy for this record type
            if ($siteId) {
                $sitePolicy = $specificPolicies
                    ->where('applies_to_type', 'site')
                    ->where('applies_to_id', $siteId)
                    ->first();
                if ($sitePolicy) {
                    return $sitePolicy->retention_months;
                }
            }

            // Check global policy for this record type
            $globalPolicy = $specificPolicies
                ->where('applies_to_type', 'global')
                ->first();
            if ($globalPolicy) {
                return $globalPolicy->retention_months;
            }
        }

        // Fall back to 'all' record type policies
        $allTypePolicies = $allPolicies->where('record_type', 'all');

        // Check site-specific 'all' policy
        if ($siteId) {
            $siteAllPolicy = $allTypePolicies
                ->where('applies_to_type', 'site')
                ->where('applies_to_id', $siteId)
                ->first();
            if ($siteAllPolicy) {
                return $siteAllPolicy->retention_months;
            }
        }

        // Check global 'all' policy
        $globalAllPolicy = $allTypePolicies
            ->where('applies_to_type', 'global')
            ->first();
        if ($globalAllPolicy) {
            return $globalAllPolicy->retention_months;
        }

        // Default based on record type
        return match ($recordType) {
            'attendance_point' => 12,
            default => 3,
        };
    }

    /**
     * Scope for active policies
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for global policies
     */
    public function scopeGlobal($query)
    {
        return $query->where('applies_to_type', 'global');
    }

    /**
     * Scope for site-specific policies
     */
    public function scopeForSite($query, int $siteId)
    {
        return $query->where('applies_to_type', 'site')
                     ->where('applies_to_id', $siteId);
    }

    /**
     * Scope for specific record type
     */
    public function scopeForRecordType($query, string $recordType)
    {
        return $query->where('record_type', $recordType);
    }
}
