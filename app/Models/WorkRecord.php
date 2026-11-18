<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkRecord extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Work records are append-only.'));
        static::deleting(fn () => throw new \LogicException('Work records are append-only.'));
    }
}
