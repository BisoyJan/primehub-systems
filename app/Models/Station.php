<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Station extends Model
{
    protected $fillable = [
        'site_id',
        'station_number',
        'campaign_id',
        'status',
        'monitor_type',
        'pc_spec_id',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function pcSpec(): BelongsTo
    {
        return $this->belongsTo(PcSpec::class);
    }

    // Scope for search functionality
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (!$search) {
            return $query;
        }

        return $query->where('station_number', 'like', "%{$search}%")
            ->orWhereHas('site', fn($q) => $q->where('name', 'like', "%{$search}%"))
            ->orWhereHas('campaign', fn($q) => $q->where('name', 'like', "%{$search}%"));
    }

    // Scope for filtering by site
    public function scopeFilterBySite(Builder $query, ?string $siteId): Builder
    {
        return $siteId ? $query->where('site_id', $siteId) : $query;
    }

    // Scope for filtering by campaign
    public function scopeFilterByCampaign(Builder $query, ?string $campaignId): Builder
    {
        return $campaignId ? $query->where('campaign_id', $campaignId) : $query;
    }

    // Scope for filtering by status
    public function scopeFilterByStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }
}
