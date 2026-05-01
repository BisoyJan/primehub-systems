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
        'issue',
        'ram_gb',
        'disk_gb',
        'available_ports',
        'notes',
        'bios_release_date',
    ];

    protected function casts(): array
    {
        return [
            'ram_gb' => 'integer',
            'disk_gb' => 'integer',
            'bios_release_date' => 'date:Y-m-d',
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
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'memory_type' => $this->memory_type,
            'ram_gb' => $this->ram_gb,
            'disk_gb' => $this->disk_gb,
            'available_ports' => $this->available_ports,
            'bios_release_date' => $this->bios_release_date?->format('Y-m-d'),
            'issue' => $this->issue,
            'notes' => $this->notes,
            'processor' => $this->processorSpecs->pluck('model')->implode(', '),
            'processorSpecs' => $this->processorSpecs->map(fn ($p) => [
                'id' => $p->id,
                'manufacturer' => $p->manufacturer,
                'model' => $p->model,
                'core_count' => $p->core_count ?? null,
                'thread_count' => $p->thread_count ?? null,
                'base_clock_ghz' => $p->base_clock_ghz ?? null,
                'boost_clock_ghz' => $p->boost_clock_ghz ?? null,
            ])->toArray(),
        ];
    }

    // Format for form selection (used in Create/Edit forms)
    public function getFormSelectionData(): array
    {
        return [
            'id' => $this->id,
            'pc_number' => $this->pc_number,
            'model' => $this->model,
            'memory_type' => $this->memory_type,
            'ram_gb' => $this->ram_gb,
            'disk_gb' => $this->disk_gb,
            'available_ports' => $this->available_ports,
            'issue' => $this->issue,
            'notes' => $this->notes,
            'processor' => $this->processorSpecs->pluck('model')->implode(', '),
            'processor_manufacturer' => $this->processorSpecs->pluck('manufacturer')->unique()->filter()->implode(', '),
        ];
    }
}
