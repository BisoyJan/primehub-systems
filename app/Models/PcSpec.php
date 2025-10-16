<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PcSpec extends Model
{
    use HasFactory;
    protected $fillable = [
        'manufacturer',
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
        'issue',
    ];

    public function ramSpecs()
    {
        return $this->belongsToMany(
            RamSpec::class,
            'pc_spec_ram_spec',
            'pc_spec_id',
            'ram_spec_id'
        )->withPivot('quantity')->withTimestamps();
    }

    public function diskSpecs()
    {
        return $this->belongsToMany(
            DiskSpec::class,
            'pc_spec_disk_spec',
            'pc_spec_id',
            'disk_spec_id'
        );
    }

    public function processorSpecs()
    {
        return $this->belongsToMany(
            ProcessorSpec::class,
            'pc_spec_processor_spec',
            'pc_spec_id',
            'processor_spec_id'
        );
    }

    public function stations()
    {
        return $this->hasMany(Station::class);
    }

    // Format PC Spec details for frontend display
    public function getFormattedDetails(): array
    {
        $this->load(['ramSpecs', 'diskSpecs', 'processorSpecs']);

        return [
            'id' => $this->id,
            'model' => $this->model,
            'ram' => $this->ramSpecs->map(fn($ram) => $ram->model)->implode(', '),
            'ram_gb' => $this->ramSpecs->sum('capacity_gb'),
            'ram_capacities' => $this->ramSpecs->map(fn($ram) => $ram->capacity_gb . ' GB')->implode(' + '),
            'ram_ddr' => $this->ramSpecs->first()?->type ?? 'N/A',
            'disk' => $this->diskSpecs->map(fn($disk) => $disk->model)->implode(', '),
            'disk_gb' => $this->diskSpecs->sum('capacity_gb'),
            'disk_capacities' => $this->diskSpecs->map(fn($disk) => $disk->capacity_gb . ' GB')->implode(' + '),
            'disk_type' => $this->getFormattedDiskType(),
            'processor' => $this->processorSpecs->pluck('model')->implode(', '),
            'issue' => $this->issue,
        ];
    }

    // Get formatted disk type (handles multiple types)
    private function getFormattedDiskType(): string
    {
        $diskTypes = $this->diskSpecs->pluck('drive_type')->unique()->values();
        return $diskTypes->count() > 1
            ? $diskTypes->implode('/')
            : ($diskTypes->first() ?? 'N/A');
    }

    // Format for form selection (used in Create/Edit forms)
    public function getFormSelectionData(): array
    {
        return [
            'id' => $this->id,
            'model' => $this->model,
            'ram' => $this->ramSpecs->map(fn($ram) => $ram->model)->implode(', '),
            'ram_gb' => $this->ramSpecs->map(fn($ram) => $ram->capacity_gb)->implode(' + '),
            'ram_capacities' => $this->ramSpecs->map(fn($ram) => $ram->capacity_gb . ' GB')->implode(' + '),
            'ram_ddr' => $this->ramSpecs->first()?->type ?? 'N/A',
            'disk' => $this->diskSpecs->map(fn($disk) => $disk->model)->implode(', '),
            'disk_gb' => $this->diskSpecs->map(fn($disk) => $disk->capacity_gb)->implode(' + '),
            'disk_capacities' => $this->diskSpecs->map(fn($disk) => $disk->capacity_gb . ' GB')->implode(' + '),
            'disk_type' => $this->getFormattedDiskType(),
            'processor' => $this->processorSpecs->pluck('model')->implode(', '),
        ];
    }
}
