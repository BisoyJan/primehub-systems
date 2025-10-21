<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasSpecSearch;

class RamSpec extends Model
{
    use HasFactory, HasSpecSearch;

    protected $fillable = [
        'manufacturer',
        'model',
        'capacity_gb',
        'type',
        'speed',
        'form_factor',
        'voltage',
    ];

    protected function casts(): array
    {
        return [
            'capacity_gb' => 'integer',
            'speed' => 'integer',
            'voltage' => 'decimal:2',
        ];
    }

    public function pcSpecs()
    {
        return $this->belongsToMany(
            PcSpec::class,
            'pc_spec_ram_spec',
            'ram_spec_id',
            'pc_spec_id'
        );
    }

    public function stock()
    {
        return $this->morphOne(Stock::class, 'stockable');
    }
}
