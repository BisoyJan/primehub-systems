<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcTransfer extends Model
{
    protected $fillable = [
        'from_station_id',
        'to_station_id',
        'pc_spec_id',
        'user_id',
        'transfer_type',
        'notes',
    ];

    public function fromStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    public function toStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }

    public function pcSpec(): BelongsTo
    {
        return $this->belongsTo(PcSpec::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
