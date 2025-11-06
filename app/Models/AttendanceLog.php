<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_no',
        'user_id_from_file',
        'employee_name',
        'mode',
        'log_datetime',
        'file_date',
    ];

    protected $casts = [
        'log_datetime' => 'datetime',
        'file_date' => 'date',
    ];
}
