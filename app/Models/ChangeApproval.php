<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeApproval extends Model
{
    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(UpdatePlan::class, 'update_plan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Approvals are append-only; resubmission creates a new decision.'));
        static::deleting(fn () => throw new \LogicException('Approvals are append-only.'));
    }
}
