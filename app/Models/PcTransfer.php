<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PcTransfer extends Model
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
        'from_station_id',
        'to_station_id',
        'pc_spec_id',
        'user_id',
        'transfer_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'from_station_id' => 'integer',
            'to_station_id' => 'integer',
            'pc_spec_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

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
