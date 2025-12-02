<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PcMaintenance extends Model
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
        'pc_spec_id',
        'last_maintenance_date',
        'next_due_date',
        'maintenance_type',
        'notes',
        'performed_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'last_maintenance_date' => 'date',
            'next_due_date' => 'date',
            'pc_spec_id' => 'integer',
        ];
    }

    /**
     * Get the PC spec that this maintenance is for.
     */
    public function pcSpec(): BelongsTo
    {
        return $this->belongsTo(PcSpec::class);
    }

    /**
     * Get the current station where this PC is assigned.
     * Returns the station that currently has this pc_spec_id assigned.
     */
    public function currentStation()
    {
        return $this->hasOneThrough(
            Station::class,
            PcSpec::class,
            'id', // Foreign key on pc_specs table
            'pc_spec_id', // Foreign key on stations table
            'pc_spec_id', // Local key on pc_maintenances table
            'id' // Local key on pc_specs table
        );
    }

    // Check if maintenance is overdue
    public function isOverdue(): bool
    {
        return Carbon::parse($this->next_due_date)->isPast();
    }

    // Get days until due
    public function daysUntilDue(): int
    {
        return Carbon::now()->diffInDays(Carbon::parse($this->next_due_date), false);
    }

    // Update status based on due date
    public function updateStatus(): void
    {
        if ($this->isOverdue()) {
            $this->status = 'overdue';
        } elseif ($this->status === 'completed') {
            $this->status = 'completed';
        } else {
            $this->status = 'pending';
        }
        $this->save();
    }
}
