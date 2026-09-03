<?php

namespace App\Traits;

use App\Support\SearchNormalizer;
use Illuminate\Database\Eloquent\Builder;

trait HasNormalizedNameSearch
{
    /**
     * Boot the trait to keep name_search_index in sync on save.
     */
    public static function bootHasNormalizedNameSearch(): void
    {
        static::saving(function ($model) {
            $values = array_map(fn (string $column) => $model->{$column}, $model->getNameSearchColumns());
            $model->name_search_index = SearchNormalizer::normalize(implode(' ', $values));
        });
    }

    /**
     * Filter by a diacritic-insensitive match against the model's name columns.
     */
    public function scopeSearchName(Builder $query, ?string $search): Builder
    {
        if (! $search) {
            return $query;
        }

        return $query->where('name_search_index', 'like', '%'.SearchNormalizer::normalize($search).'%');
    }
}
