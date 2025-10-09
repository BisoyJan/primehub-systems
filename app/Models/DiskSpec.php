<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiskSpec extends Model
{
    use HasFactory;
    protected $fillable = [
        'manufacturer',
        'model',
        'capacity_gb',
        'interface',
        'drive_type',
        'sequential_read_mb',
        'sequential_write_mb',
    ];

    public function motherboardSpecs()
    {
        return $this->belongsToMany(
            MotherboardSpec::class,
            'motherboard_spec_disk_spec',
            'disk_spec_id',
            'motherboard_spec_id'
        );
    }

    public function stock()
    {
        return $this->morphOne(Stock::class, 'stockable');
    }
}
