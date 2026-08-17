<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class SocialGroup extends Model
{
    use HasFactory;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_CAMPAIGN = 'campaign';

    protected $fillable = [
        'name',
        'description',
        'visibility',
        'campaign_id',
        'created_by',
        'is_archived',
        'theme_color',
        'theme_background',
        'theme_background_image_path',
    ];

    protected $appends = ['theme_background_image_url'];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'social_group_members')
            ->withPivot(['role', 'joined_at', 'last_read_at'])
            ->withTimestamps();
    }

    public function memberRecords(): HasMany
    {
        return $this->hasMany(SocialGroupMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(SocialGroupInvite::class);
    }

    public function isMember(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->memberRecords()->where('user_id', $userId)->exists();
    }

    public function getThemeBackgroundImageUrlAttribute(): ?string
    {
        if (! $this->theme_background_image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->theme_background_image_path);
    }
}
