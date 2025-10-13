<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Station extends Model
{
    protected $fillable = [
        'site_id',
        'station_number',
        'campaign_id',
        'status',
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
}
