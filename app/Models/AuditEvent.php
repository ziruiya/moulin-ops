<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit events are append-only.'));
    }
}
