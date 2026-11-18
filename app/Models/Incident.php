<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    protected $guarded = [];

    protected $casts = ['acknowledged_at' => 'datetime', 'started_investigation_at' => 'datetime', 'detected_at' => 'datetime', 'reported_at' => 'datetime', 'started_at' => 'datetime', 'mitigated_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'resolution_verified_at' => 'datetime', 'self_verified' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(function (self $incident) {
            if ($incident->getOriginal('status') !== 'CLOSED') {
                return;
            }

            throw new \LogicException('Closed incidents cannot be overwritten; reopen as a new linked incident.');
        });
    }

    public function reopenedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reopened_from_id');
    }

    public function reopenings(): HasMany
    {
        return $this->hasMany(self::class, 'reopened_from_id');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'incident_assets')->withTimestamps();
    }

    public function updatePlans(): BelongsToMany
    {
        return $this->belongsToMany(UpdatePlan::class, 'incident_update_plans')->withTimestamps();
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(AssetComponent::class, 'incident_components', 'incident_id', 'component_id')->withPivot('asset_id')->withTimestamps();
    }

    public function workRecords(): HasMany
    {
        return $this->hasMany(WorkRecord::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolution_verified_by');
    }
}
