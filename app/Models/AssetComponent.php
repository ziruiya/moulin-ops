<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AssetComponent extends Model
{
    protected $guarded = [];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function lifecycleRecords(): HasMany
    {
        return $this->hasMany(LifecycleRecord::class, 'component_id');
    }

    public function currentLifecycle(): HasOne
    {
        return $this->hasOne(LifecycleRecord::class, 'component_id')->latestOfMany('verified_at');
    }
}
