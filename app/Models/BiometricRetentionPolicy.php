<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricRetentionPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'retention_months',
        'applies_to_type',
        'applies_to_id',
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
     */
    public static function getRetentionMonths(?int $siteId = null): int
    {
        $query = self::where('is_active', true);

        // Find matching policies
        $policies = $query->get()->sortByDesc('priority');

        foreach ($policies as $policy) {
            if ($policy->applies_to_type === 'site' && $policy->applies_to_id == $siteId) {
                return $policy->retention_months;
            }
        }

        // Fall back to global policy
        $globalPolicy = $policies->where('applies_to_type', 'global')->first();
        if ($globalPolicy) {
            return $globalPolicy->retention_months;
        }

        // Default
        return 3;
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
}
