<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\ProcessorSpec;

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

    public function processorSpecs()
    {
        return $this->belongsToMany(
            ProcessorSpec::class,
            'processor_spec_disk_spec',
            'disk_spec_id',
            'processor_spec_id'
        );
    }

    public function stock()
    {
        return $this->morphOne(Stock::class, 'stockable');
    }
}
