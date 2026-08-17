<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class SocialDirectThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'name',
        'is_group',
        'image_path',
        'theme_color',
        'theme_background',
        'theme_background_image_path',
        'last_message_at',
    ];

    protected $appends = ['theme_background_image_url', 'image_url'];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'last_message_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isGroup(): bool
    {
        return $this->is_group;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SocialDirectThreadParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SocialDirectMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SocialDirectMessage::class)->latestOfMany();
    }

    public function getThemeBackgroundImageUrlAttribute(): ?string
    {
        if (! $this->theme_background_image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->theme_background_image_path);
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }
}
