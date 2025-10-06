<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $fillable = ['quantity'];

    public function stockable()
    {
        return $this->morphTo();
    }

}
