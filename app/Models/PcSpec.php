<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

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
        'memory_type',
        'm2_slots',
        'sata_ports',
        'issue',
        'ram_gb',
        'disk_gb',
        'available_ports',
    ];

    protected function casts(): array
    {
        return [
            'm2_slots' => 'integer',
            'sata_ports' => 'integer',
            'ram_gb' => 'integer',
            'disk_gb' => 'integer',
        ];
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
        $this->load(['processorSpecs']);

        return [
            'id' => $this->id,
            'pc_number' => $this->pc_number,
            'model' => $this->model,
            'ram_gb' => $this->ram_gb,
            'disk_gb' => $this->disk_gb,
            'available_ports' => $this->available_ports,
            'processor' => $this->processorSpecs->pluck('model')->implode(', '),
            'issue' => $this->issue,
        ];
    }

    // Format for form selection (used in Create/Edit forms)
    public function getFormSelectionData(): array
    {
        return [
            'id' => $this->id,
            'pc_number' => $this->pc_number,
            'model' => $this->model,
            'ram_gb' => $this->ram_gb,
            'disk_gb' => $this->disk_gb,
            'available_ports' => $this->available_ports,
            'processor' => $this->processorSpecs->pluck('model')->implode(', '),
        ];
    }
}
