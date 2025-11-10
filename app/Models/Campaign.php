<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Campaign extends Model
{
    use HasFactory;
    protected $fillable = ['name'];

    // Scope for search functionality
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (!$search) {
            return $query;
        }

        return $query->where('name', 'like', "%{$search}%");
    }

    // Relationship to stations
    public function stations()
    {
        return $this->hasMany(Station::class);
    }
}
