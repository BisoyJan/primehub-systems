<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasSpecSearch
{
    /**
     * Scope for searching by manufacturer and model
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('manufacturer', 'like', "%{$search}%")
              ->orWhere('model', 'like', "%{$search}%");

            // Add additional searchable fields if they exist
            if (in_array('type', $this->getFillable())) {
                $q->orWhere('type', 'like', "%{$search}%");
            }
            if (in_array('interface', $this->getFillable())) {
                $q->orWhere('interface', 'like', "%{$search}%");
            }
            if (in_array('drive_type', $this->getFillable())) {
                $q->orWhere('drive_type', 'like', "%{$search}%");
            }
            if (in_array('form_factor', $this->getFillable())) {
                $q->orWhere('form_factor', 'like', "%{$search}%");
            }
            if (in_array('capacity_gb', $this->getFillable())) {
                $q->orWhere('capacity_gb', 'like', "%{$search}%");
            }
        });
    }
}
