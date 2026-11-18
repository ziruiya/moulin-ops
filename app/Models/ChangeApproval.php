<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ChangeApproval extends Model
{
    public const INVALIDATED_EVENT = 'approval_invalidated';

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

    public function invalidation(): MorphOne
    {
        return $this->morphOne(AuditEvent::class, 'auditable')->where('event_type', self::INVALIDATED_EVENT);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Approvals are append-only; resubmission creates a new decision.'));
        static::deleting(fn () => throw new \LogicException('Approvals are append-only.'));
    }
}
