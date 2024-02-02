<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    protected static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->uuid = (string)Str::orderedUuid();
        });
    }
}
