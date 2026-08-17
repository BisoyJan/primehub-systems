<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialDirectMessage extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'social_direct_thread_id',
        'user_id',
        'parent_id',
        'message',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(SocialDirectThread::class, 'social_direct_thread_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SocialDirectMessage::class, 'parent_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(SocialReaction::class, 'reactable');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(SocialAttachment::class, 'attachable');
    }
}
