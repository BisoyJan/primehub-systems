<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessorSpec extends Model
{
    use HasFactory;
    protected $fillable = [
        'brand',
        'series',
        'socket_type',
        'core_count',
        'thread_count',
        'base_clock_ghz',
        'boost_clock_ghz',
        'integrated_graphics',
        'tdp_watts',
    ];

    public function motherboardSpecs()
    {
        return $this->belongsToMany(
            MotherboardSpec::class,
            'motherboard_spec_processor_spec',
            'processor_spec_id',
            'motherboard_spec_id'
        );
    }

    public function stock()
    {
        return $this->morphOne(Stock::class, 'stockable');
    }
}
