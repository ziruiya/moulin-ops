<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpdatePlan extends Model
{
    protected $guarded = [];

    protected $casts = ['backup_verified' => 'boolean', 'restore_test_passed' => 'boolean', 'planned_start_at' => 'datetime', 'planned_end_at' => 'datetime', 'actual_start_at' => 'datetime', 'actual_end_at' => 'datetime'];

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'update_plan_assets')->withTimestamps();
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(AssetComponent::class, 'update_plan_components', 'update_plan_id', 'component_id')->withPivot('asset_id')->withTimestamps();
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'update_plan_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ChangeApproval::class, 'update_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
