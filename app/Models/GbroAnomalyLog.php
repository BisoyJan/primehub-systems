<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GbroAnomalyLog extends Model
{
    protected $fillable = [
        'batch_id',
        'trigger',
        'user_id',
        'attendance_point_id',
        'type',
        'expected',
        'actual',
        'repaired',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'repaired' => 'boolean',
            'context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendancePoint(): BelongsTo
    {
        return $this->belongsTo(AttendancePoint::class);
    }
}
