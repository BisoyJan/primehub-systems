<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasSpecSearch;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ProcessorSpec extends Model
{
    use HasFactory, HasSpecSearch, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'manufacturer',
        'model',
        'socket_type',
        'core_count',
        'thread_count',
        'base_clock_ghz',
        'boost_clock_ghz',
        'integrated_graphics',
        'tdp_watts',
    ];

    protected function casts(): array
    {
        return [
            'core_count' => 'integer',
            'thread_count' => 'integer',
            'base_clock_ghz' => 'decimal:2',
            'boost_clock_ghz' => 'decimal:2',
            'integrated_graphics' => 'boolean',
            'tdp_watts' => 'integer',
        ];
    }

    public function pcSpecs()
    {
        return $this->belongsToMany(
            PcSpec::class,
            'pc_spec_processor_spec',
            'processor_spec_id',
            'pc_spec_id'
        );
    }

    public function stock()
    {
        return $this->morphOne(Stock::class, 'stockable');
    }
}
