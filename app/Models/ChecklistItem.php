<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    protected $table = 'update_checklist_items';

    protected $guarded = [];

    protected $casts = ['is_required' => 'boolean', 'completed_at' => 'datetime', 'waived_at' => 'datetime'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(UpdatePlan::class, 'update_plan_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            if ($item->getOriginal('status') === 'Failed' && $item->isDirty('status') && $item->status === 'Passed') {
                throw new \DomainException('Failed checklist items require a correction record before passing.');
            }
        });
    }
}
