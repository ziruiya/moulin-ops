<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Asset extends Model
{
    protected $guarded = [];

    protected $casts = ['internet_exposed' => 'boolean', 'last_verified_at' => 'datetime'];

    public function isProduction(): bool
    {
        return strcasecmp($this->environment ?? '', 'Production') === 0;
    }

    public function components(): HasMany
    {
        return $this->hasMany(AssetComponent::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'source_asset_id');
    }

    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'target_asset_id');
    }

    public function workRecords(): HasMany
    {
        return $this->hasMany(WorkRecord::class);
    }

    public function latestRestoreTest(): HasOne
    {
        return $this->hasOne(WorkRecord::class)
            ->ofMany(['performed_at' => 'max', 'id' => 'max'], fn ($query) => $query->where('record_type', 'Restore'));
    }
}
