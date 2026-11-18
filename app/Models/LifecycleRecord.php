<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LifecycleRecord extends Model
{
    protected $table = 'component_lifecycle_records';

    protected $guarded = [];

    protected $casts = ['verified_at' => 'datetime'];
}
