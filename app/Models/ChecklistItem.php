<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistItem extends Model
{
    public const PHASES = [
        1 => '1 Impact Assessment',
        2 => '2 Pre-change Validation',
        3 => '3 Backup and Rollback Preparation',
        4 => '4 Implementation',
        5 => '5 Post-change Verification',
    ];

    public const WAIVER_SCOPES = ['item' => '項目', 'phase' => '段階', 'plan' => '計画'];

    protected $table = 'update_checklist_items';

    protected $guarded = [];

    protected $casts = ['is_required' => 'boolean', 'completed_at' => 'datetime', 'waived_at' => 'datetime'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(UpdatePlan::class, 'update_plan_id');
    }

    public function passedCorrections(): HasMany
    {
        return $this->hasMany(self::class, 'correction_of_id')->where('status', 'Passed')
            ->whereNotNull('evidence')->where('evidence', '<>', '');
    }

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            if ($item->getOriginal('status') === 'Failed' || $item->getOriginal('correction_of_id')) {
                throw new \DomainException('Failed checklist items and their corrections are retained as recorded.');
            }
        });
    }
}
