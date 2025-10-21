<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Stock extends Model
{
    use HasFactory;

    protected $table = 'stocks';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'integer',
        'reserved' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Polymorphic owner (RamSpec, DiskSpec, ProcessorSpec, ...)
     */
    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }
}
