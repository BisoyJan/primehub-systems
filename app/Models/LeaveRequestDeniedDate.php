<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestDeniedDate extends Model
{
    use HasFactory;

    protected $fillable = [
        'leave_request_id',
        'denied_date',
        'denial_reason',
        'denied_by',
    ];

    protected function casts(): array
    {
        return [
            'denied_date' => 'date',
        ];
    }

    /**
     * Get the leave request this denied date belongs to.
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /**
     * Get the user who denied this date.
     */
    public function denier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'denied_by');
    }
}
