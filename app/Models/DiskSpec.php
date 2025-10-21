<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasSpecSearch;

class DiskSpec extends Model
{
    use HasFactory, HasSpecSearch;

    protected $fillable = [
        'manufacturer',
        'model',
        'capacity_gb',
        'interface',
        'drive_type',
        'sequential_read_mb',
        'sequential_write_mb',
    ];

    protected function casts(): array
    {
        return [
            'capacity_gb' => 'integer',
            'sequential_read_mb' => 'integer',
            'sequential_write_mb' => 'integer',
        ];
    }

    public function pcSpecs()
    {
        return $this->belongsToMany(
            PcSpec::class,
            'pc_spec_disk_spec',
            'disk_spec_id',
            'pc_spec_id'
        );
    }

    public function stock()
    {
        return $this->morphOne(Stock::class, 'stockable');
    }
}
