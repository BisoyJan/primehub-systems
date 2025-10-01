<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RamSpec extends Model
{
    use HasFactory;
    protected $fillable = [
        'manufacturer',
        'model',
        'capacity_gb',
        'type',
        'speed',
        'form_factor',
        'voltage',
    ];
}
