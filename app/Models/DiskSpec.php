<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiskSpec extends Model
{
    use HasFactory;
    protected $fillable = [
        'manufacturer',
        'model_number',
        'capacity_gb',
        'interface',
        'drive_type',
        'sequential_read_mb',
        'sequential_write_mb',
    ];
}
