<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetRelationship extends Model
{
    protected $guarded = [];

    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_asset_id');
    }

    public function targetAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'target_asset_id');
    }
}
