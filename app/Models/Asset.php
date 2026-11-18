<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $guarded = [];

    public function components(): HasMany
    {
        return $this->hasMany(AssetComponent::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'source_asset_id');
    }

    public function workRecords(): HasMany
    {
        return $this->hasMany(WorkRecord::class);
    }
}
