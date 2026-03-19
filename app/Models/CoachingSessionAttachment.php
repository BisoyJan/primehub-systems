<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachingSessionAttachment extends Model
{
    protected $fillable = [
        'coaching_session_id',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
    ];

    public function coachingSession(): BelongsTo
    {
        return $this->belongsTo(CoachingSession::class);
    }
}
