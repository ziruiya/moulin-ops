<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpdatePlan extends Model
{
    public const FINAL_STATES = ['COMPLETED', 'ROLLED_BACK', 'ROLLBACK_FAILED', 'CANCELLED', 'EMERGENCY_REVIEW'];

    public const STATUS_LABELS = [
        'DRAFT' => '下書き',
        'ASSESSMENT' => '影響調査中',
        'TESTING' => '事前検証中',
        'PENDING_APPROVAL' => '承認待ち',
        'APPROVED' => '承認済み',
        'SCHEDULED' => '実施予定',
        'EXECUTING' => '実施中',
        'VERIFYING' => '動作確認中',
        'COMPLETED' => '完了',
        'REJECTED' => '差し戻し',
        'BLOCKED' => '保留',
        'CANCELLED' => '中止',
        'FAILED' => '実施失敗',
        'ROLLBACK_REQUIRED' => 'ロールバックが必要',
        'ROLLING_BACK' => 'ロールバック中',
        'ROLLED_BACK' => 'ロールバック済み',
        'ROLLBACK_FAILED' => 'ロールバック失敗',
        'EMERGENCY_REVIEW' => '事後レビュー待ち',
    ];

    protected $guarded = [];

    protected $casts = ['backup_verified' => 'boolean', 'restore_test_passed' => 'boolean', 'operator_id' => 'integer', 'planned_start_at' => 'datetime', 'planned_end_at' => 'datetime', 'actual_start_at' => 'datetime', 'actual_end_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            if (in_array($plan->getOriginal('status'), self::FINAL_STATES, true)) {
                throw new \DomainException('Concluded update plans are retained as recorded.');
            }
        });
    }

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

    public function validApprovals(): HasMany
    {
        return $this->approvals()->where('decision', 'APPROVED')->whereDoesntHave('invalidation');
    }

    public function workRecords(): HasMany
    {
        return $this->hasMany(WorkRecord::class, 'update_plan_id');
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'incident_update_plans')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function sourceAssessment(): BelongsTo
    {
        return $this->belongsTo(VulnerabilityComponentAssessment::class, 'source_assessment_id');
    }
}
