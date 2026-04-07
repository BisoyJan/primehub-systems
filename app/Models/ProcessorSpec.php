<?php

namespace App\Models;

use App\Traits\HasSpecSearch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProcessorSpec extends Model
{
    use HasFactory, HasSpecSearch, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'manufacturer',
        'model',
        'core_count',
        'thread_count',
        'base_clock_ghz',
        'boost_clock_ghz',
        'release_date',
    ];

    protected function casts(): array
    {
        return [
            'core_count' => 'integer',
            'thread_count' => 'integer',
            'base_clock_ghz' => 'decimal:2',
            'boost_clock_ghz' => 'decimal:2',
            'release_date' => 'date',
        ];
    }

    public function pcSpecs()
    {
        return $this->belongsToMany(
            PcSpec::class,
            'pc_spec_processor_spec',
            'processor_spec_id',
            'pc_spec_id'
        );
    }
}
