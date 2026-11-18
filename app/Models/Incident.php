<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Incident extends Model
{
    protected $guarded = [];

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'incident_assets')->withTimestamps();
    }

    public function updatePlans(): BelongsToMany
    {
        return $this->belongsToMany(UpdatePlan::class, 'incident_update_plans')->withTimestamps();
    }
}
