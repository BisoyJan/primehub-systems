<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PcSpec extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'pc_number',
        'manufacturer',
        'model',
        'form_factor',
        'memory_type',
        'ram_slots',
        'max_ram_capacity_gb',
        'max_ram_speed',
        'm2_slots',
        'sata_ports',
        'issue',
    ];

    protected function casts(): array
    {
        return [
            'ram_slots' => 'integer',
            'max_ram_capacity_gb' => 'integer',
            'max_ram_speed' => 'integer',
            'm2_slots' => 'integer',
            'sata_ports' => 'integer',
        ];
    }

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

    public function monitors()
    {
        return $this->belongsToMany(
            MonitorSpec::class,
            'monitor_pc_spec',
            'pc_spec_id',
            'monitor_spec_id'
        )->withPivot('quantity')->withTimestamps();
    }

    public function stations()
    {
        return $this->hasMany(Station::class);
    }

    public function transfers()
    {
        return $this->hasMany(PcTransfer::class);
    }

    public function maintenances()
    {
        return $this->hasMany(PcMaintenance::class);
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    // Format PC Spec details for frontend display
    public function getFormattedDetails(): array
    {
        $this->load(['ramSpecs', 'diskSpecs', 'processorSpecs']);

        return [
            'id' => $this->id,
            'pc_number' => $this->pc_number,
            'model' => $this->model,
            'ram' => $this->ramSpecs->map(fn($ram) => $ram->model)->implode(', '),
            'ram_gb' => $this->ramSpecs->sum(fn($ram) => $ram->capacity_gb * ($ram->pivot->quantity ?? 1)),
            'ram_capacities' => $this->ramSpecs->flatMap(fn($ram) =>
                array_fill(0, $ram->pivot->quantity ?? 1, $ram->capacity_gb . ' GB')
            )->implode(' + '),
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
            'pc_number' => $this->pc_number,
            'model' => $this->model,
            'ram' => $this->ramSpecs->map(fn($ram) => $ram->model)->implode(', '),
            'ram_gb' => $this->ramSpecs->map(fn($ram) => $ram->capacity_gb)->implode(' + '),
            'ram_capacities' => $this->ramSpecs->flatMap(fn($ram) =>
                array_fill(0, $ram->pivot->quantity ?? 1, $ram->capacity_gb . ' GB')
            )->implode(' + '),
            'ram_ddr' => $this->ramSpecs->first()?->type ?? 'N/A',
            'disk' => $this->diskSpecs->map(fn($disk) => $disk->model)->implode(', '),
            'disk_gb' => $this->diskSpecs->map(fn($disk) => $disk->capacity_gb)->implode(' + '),
            'disk_capacities' => $this->diskSpecs->map(fn($disk) => $disk->capacity_gb . ' GB')->implode(' + '),
            'disk_type' => $this->getFormattedDiskType(),
            'processor' => $this->processorSpecs->pluck('model')->implode(', '),
        ];
    }
}
