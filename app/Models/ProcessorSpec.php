<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\PcSpec;
use App\Traits\HasSpecSearch;

class ProcessorSpec extends Model
{
    use HasFactory, HasSpecSearch;

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

    public function pcSpecs()
    {
        return $this->belongsToMany(
            PcSpec::class,
            'pc_spec_processor_spec',
            'processor_spec_id',
            'pc_spec_id'
        );
    }

    public function processorSpecs()
    {
        return $this->belongsToMany(
            ProcessorSpec::class,
            'processor_spec_processor_spec',
            'processor_spec_id',
            'processor_spec_id'
        );
    }

    public function stock()
    {
        return $this->morphOne(Stock::class, 'stockable');
    }
}
