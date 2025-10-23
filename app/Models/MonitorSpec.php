<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MonitorSpec extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand',
        'model',
        'screen_size',
        'resolution',
        'panel_type',
        'ports',
        'notes',
    ];

    protected $casts = [
        'screen_size' => 'decimal:1',
        'ports' => 'array',
    ];

    public function pcSpecs(): BelongsToMany
    {
        return $this->belongsToMany(PcSpec::class, 'monitor_pc_spec')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class, 'monitor_station')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function stock()
    {
        return $this->morphOne(Stock::class, 'stockable');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->brand} {$this->model}";
    }
}
