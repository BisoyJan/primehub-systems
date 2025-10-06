<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MotherboardSpec extends Model
{
    use HasFactory;
    protected $fillable = [
        'brand',
        'model',
        'chipset',
        'form_factor',
        'socket_type', 
        'memory_type',
        'ram_slots',
        'max_ram_capacity_gb',
        'max_ram_speed',
        'pcie_slots',
        'm2_slots',
        'sata_ports',
        'usb_ports',
        'ethernet_speed',
        'wifi',
    ];

    public function ramSpecs()
    {
        return $this->belongsToMany(
            RamSpec::class,
            'motherboard_spec_ram_spec',
            'motherboard_spec_id',
            'ram_spec_id'
        );
    }

    public function diskSpecs()
    {
        return $this->belongsToMany(
            DiskSpec::class,
            'motherboard_spec_disk_spec',
            'motherboard_spec_id',
            'disk_spec_id'
        );
    }

    public function processorSpecs()
    {
        return $this->belongsToMany(
            ProcessorSpec::class,
            'motherboard_spec_processor_spec',
            'motherboard_spec_id',
            'processor_spec_id'
        );
    }
}
