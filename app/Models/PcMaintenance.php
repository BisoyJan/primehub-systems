<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class PcMaintenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'station_id',
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
            'station_id' => 'integer',
        ];
    }

    /**
     * Get the station that this maintenance is for.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the PC spec through the station relationship.
     */
    public function pcSpec()
    {
        return $this->hasOneThrough(
            PcSpec::class,
            Station::class,
            'id', // Foreign key on stations table
            'id', // Foreign key on pc_specs table
            'station_id', // Local key on pc_maintenances table
            'pc_spec_id' // Local key on stations table
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
