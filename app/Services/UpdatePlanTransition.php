<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\ChangeApproval;
use App\Models\ChecklistItem;
use App\Models\Incident;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\WorkRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdatePlanTransition
{
    private const FLOW = ['DRAFT' => ['ASSESSMENT', 'CANCELLED'], 'ASSESSMENT' => ['TESTING', 'BLOCKED', 'CANCELLED'], 'TESTING' => ['PENDING_APPROVAL', 'BLOCKED', 'CANCELLED'], 'PENDING_APPROVAL' => ['APPROVED', 'REJECTED', 'BLOCKED', 'CANCELLED'], 'REJECTED' => ['ASSESSMENT'], 'APPROVED' => ['SCHEDULED', 'BLOCKED', 'CANCELLED'], 'SCHEDULED' => ['EXECUTING', 'BLOCKED', 'CANCELLED'], 'EXECUTING' => ['VERIFYING', 'FAILED', 'ROLLBACK_REQUIRED', 'BLOCKED'], 'VERIFYING' => ['COMPLETED', 'ROLLBACK_REQUIRED', 'EMERGENCY_REVIEW', 'FAILED'], 'FAILED' => ['ROLLBACK_REQUIRED'], 'ROLLBACK_REQUIRED' => ['ROLLING_BACK'], 'ROLLING_BACK' => ['ROLLED_BACK', 'ROLLBACK_FAILED'], 'ROLLBACK_FAILED' => [], 'ROLLED_BACK' => [], 'COMPLETED' => [], 'BLOCKED' => [], 'CANCELLED' => [], 'EMERGENCY_REVIEW' => []];

    private const APPROVAL_REQUIRED_STATES = ['APPROVED', 'SCHEDULED', 'EXECUTING', 'VERIFYING', 'COMPLETED'];

    private const REQUIRED_THROUGH_PHASE = [
        'TESTING' => 1,
        'PENDING_APPROVAL' => 2,
        'APPROVED' => 2,
        'SCHEDULED' => 2,
        'EXECUTING' => 3,
        'VERIFYING' => 4,
        'COMPLETED' => 5,
        'EMERGENCY_REVIEW' => 5,
    ];

    public function __construct(private AuditLogger $audit) {}

    public function approve(UpdatePlan $plan, User $reviewer, string $decision, ?string $comments): void
    {
        DB::transaction(function () use ($plan, $reviewer, $decision, $comments): void {
            $plan = UpdatePlan::query()->lockForUpdate()->findOrFail($plan->id);
            Gate::forUser($reviewer)->authorize('approve', $plan);
            if ($plan->validApprovals()->exists()) {
                throw ValidationException::withMessages(['decision' => '承認記録は変更できません。']);
            }
            $approval = $plan->approvals()->create([
                'initiated_by' => $plan->created_by,
                'executed_by' => $plan->operator_id,
                'approver_id' => $reviewer->id,
                'decision' => $decision,
                'comments' => $comments,
                'decided_at' => now(),
            ]);
            $this->audit->log($plan, 'approval_'.$decision, [], ['approval_id' => $approval->id, 'approver_id' => $reviewer->id], $reviewer->id);
        });
    }

    public function transition(UpdatePlan $plan, string $to, array $data = [], ?User $actor = null): UpdatePlan
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($plan, $to, $data, $actor): UpdatePlan {
            $plan = UpdatePlan::query()->lockForUpdate()->findOrFail($plan->id);
            $from = $plan->status;
            if ($from === 'BLOCKED') {
                if ($to !== $plan->blocked_from_status) {
                    throw ValidationException::withMessages(['status' => '保留中の計画は直前の状態に戻してください。']);
                }
            } elseif (! in_array($to, self::FLOW[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "{$from} から {$to} には変更できません。"]);
            }
            $approval = $this->guard($plan, $to, $data, $actor);
            $plan->status = $to;
            if ($to === 'BLOCKED') {
                $plan->blocked_from_status = $from;
            }
            if ($from === 'BLOCKED') {
                $plan->blocked_from_status = null;
            }
            if ($to === 'APPROVED') {
                $plan->approver_id = $approval->approver_id;
            }
            if ($to === 'EXECUTING') {
                $plan->actual_start_at ??= now();
            }
            if (in_array($to, ['COMPLETED', 'ROLLED_BACK', 'ROLLBACK_FAILED', 'EMERGENCY_REVIEW'], true)) {
                $plan->actual_end_at = now();
            }
            if ($to === 'ROLLED_BACK') {
                $plan->completion_result = $data['restore_verification_result'];
            } elseif (array_key_exists('completion_result', $data)) {
                $plan->completion_result = $data['completion_result'];
            }
            $plan->save();
            $this->audit->log($plan, 'status_transition', ['status' => $from], ['status' => $to, 'blocked_from_status' => $plan->blocked_from_status], $actor?->id);

            if ($to === 'ROLLBACK_FAILED' || ($to === 'ROLLBACK_REQUIRED' && filled($data['incident']['title'] ?? null))) {
                $this->recordIncident($plan, $data, $actor);
            } elseif ($to === 'ROLLED_BACK') {
                $this->recordRecovery($plan, $data, $actor);
            }
            if (in_array($to, ['EXECUTING', 'VERIFYING', 'FAILED', 'ROLLING_BACK', 'ROLLBACK_REQUIRED', 'ROLLBACK_FAILED', 'ROLLED_BACK', 'COMPLETED'], true)) {
                $this->recordWork($plan, $to, $data, $actor);
            }

            return $plan;
        });
    }

    private function guard(UpdatePlan $plan, string $to, array $data, ?User $actor): ?ChangeApproval
    {
        if ($to === 'TESTING' && blank($plan->impact)) {
            throw ValidationException::withMessages(['impact' => '影響調査の結果を記録してください。']);
        }
        if ($to === 'PENDING_APPROVAL' && (blank($plan->test_plan) || ! $this->passedPhase($plan, 2, withEvidence: true))) {
            throw ValidationException::withMessages(['checklist' => 'テスト計画と事前検証の証跡が必要です。']);
        }
        $approval = null;
        if (in_array($to, self::APPROVAL_REQUIRED_STATES, true)) {
            $approval = $plan->validApprovals()->latest('id')->first();
            if (! $approval) {
                throw ValidationException::withMessages(['approval' => '独立した承認者による有効な承認が必要です。']);
            }
        }
        if ($to === 'EXECUTING') {
            if (! $plan->backup_verified || blank($plan->backup_reference) || ! $plan->restore_test_passed || ! $plan->operator_id || ! $plan->planned_start_at || ! $plan->planned_end_at || $plan->planned_end_at->lte($plan->planned_start_at)) {
                throw ValidationException::withMessages(['execution' => 'バックアップ証跡、復元検証、実施担当者、有効なメンテナンス枠が必要です。']);
            }
            if ((in_array(strtoupper($plan->risk_level ?? ''), ['HIGH', 'CRITICAL'], true)
                    && ($actor?->id !== $plan->operator_id || ! $actor->roles()->where('name', 'Operator')->exists()))
                || ($actor && $plan->validApprovals()->where('approver_id', $actor->id)->exists())) {
                throw ValidationException::withMessages(['execution' => '実施担当者の権限と承認者からの独立性を確認してください。']);
            }
        }
        $lastPhase = self::REQUIRED_THROUGH_PHASE[$to] ?? null;
        if ($lastPhase !== null && $this->hasIncompleteRequiredItems($plan, array_slice(ChecklistItem::PHASES, 0, $lastPhase))) {
            throw ValidationException::withMessages(['checklist' => 'この段階までの必須チェック項目を完了してください。']);
        }
        if (in_array($to, ['COMPLETED', 'EMERGENCY_REVIEW'], true) && ! $this->passedPhase($plan, 5, withEvidence: true)) {
            throw ValidationException::withMessages(['checklist' => '更新後の確認記録が必要です。']);
        }
        if ($to === 'ROLLED_BACK') {
            if (! $this->passedPhase($plan, 5) || $this->hasIncompleteRequiredItems($plan, [ChecklistItem::PHASES[5]])) {
                throw ValidationException::withMessages(['checklist' => 'ロールバック後の確認記録が必要です。']);
            }
            if (blank($data['rollback_reason'] ?? null) || blank($data['restore_verification_result'] ?? null)) {
                throw ValidationException::withMessages(['rollback' => 'ロールバックの理由と復元検証結果が必要です。']);
            }
        }
        if ($to === 'EMERGENCY_REVIEW' && ($plan->change_type !== 'Emergency' || blank($data['completion_result'] ?? null))) {
            throw ValidationException::withMessages(['completion_result' => '緊急変更の事後レビューの記録が必要です。']);
        }
        if ($to === 'ROLLBACK_FAILED' && blank($data['incident']['title'] ?? null)) {
            throw ValidationException::withMessages(['incident' => '障害の詳細を記録してください。']);
        }

        return $approval;
    }

    private function hasIncompleteRequiredItems(UpdatePlan $plan, array $phases): bool
    {
        return $plan->checklistItems()->whereIn('phase', $phases)->where('is_required', true)
            ->whereDoesntHave('passedCorrections')
            ->where(fn ($query) => $query->whereNotIn('status', ['Passed', 'Waived'])
                ->orWhere(fn ($waiver) => $waiver->where('status', 'Waived')
                    ->where(fn ($invalid) => $invalid->whereNull('waiver_scope')->orWhereNotIn('waiver_scope', array_keys(ChecklistItem::WAIVER_SCOPES))
                        ->orWhereNull('exception_reason')->orWhere('exception_reason', '')->orWhereNull('waived_by')->orWhereNull('waived_at'))))
            ->exists();
    }

    private function passedPhase(UpdatePlan $plan, int $phase, bool $withEvidence = false): bool
    {
        return $plan->checklistItems()->where('phase', ChecklistItem::PHASES[$phase])->where('status', 'Passed')
            ->when($withEvidence, fn ($query) => $query->whereNotNull('evidence')->where('evidence', '!=', ''))->exists();
    }

    private function primaryAsset(UpdatePlan $plan): ?Asset
    {
        return $plan->components()->with('asset')->orderBy('asset_components.id')->first()?->asset
            ?? $plan->assets()->orderBy('assets.id')->first();
    }

    private function recordWork(UpdatePlan $plan, string $status, array $data, ?User $actor): void
    {
        $asset = $this->primaryAsset($plan);
        if (! $asset || ! $actor) {
            return;
        }
        $incidentId = $plan->incidents()->latest('incidents.id')->value('incidents.id');
        $details = $status === 'ROLLED_BACK'
            ? json_encode(['rollback_reason' => $data['rollback_reason'], 'restore_verification_result' => $data['restore_verification_result'], 'details' => $data['details'] ?? null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : ($data['details'] ?? null);
        $work = WorkRecord::create([
            'record_code' => $data['work_record_code'] ?? 'WR-'.strtoupper(bin2hex(random_bytes(6))),
            'record_type' => match ($status) {
                'VERIFYING' => 'Verification',
                'ROLLED_BACK' => 'Restore',
                default => 'Update',
            },
            'asset_id' => $asset->id,
            'user_id' => $actor->id,
            'update_plan_id' => $plan->id,
            'incident_id' => $incidentId,
            'summary' => "Update plan {$status}",
            'action_summary' => $data['action_summary'] ?? "変更計画の状態 {$status} を記録しました。",
            'details' => $details,
            'environment' => $asset->environment ?? 'Unknown',
            'before_state' => $status === 'ROLLED_BACK' ? '更新失敗後（更新先候補: '.$plan->target_version.'）' : $plan->current_version,
            'after_state' => match ($status) {
                'ROLLED_BACK' => $plan->current_version,
                'COMPLETED' => $plan->target_version,
                default => null,
            },
            'result' => match ($status) {
                'FAILED', 'ROLLBACK_FAILED' => 'Failed',
                'ROLLED_BACK', 'COMPLETED' => 'Success',
                default => 'Pending',
            },
            'started_at' => now(),
            'performed_at' => now(),
        ]);
        $this->audit->log($work, 'work_record_created', [], $work->only(['record_code', 'update_plan_id', 'incident_id', 'result']), $actor->id);
    }

    private function recordIncident(UpdatePlan $plan, array $data, ?User $actor): void
    {
        $user = $actor?->id ?? $plan->created_by;
        $incident = Incident::create([
            'incident_code' => $data['incident_code'] ?? 'INC-'.strtoupper(bin2hex(random_bytes(6))),
            'title' => $data['incident']['title'],
            'category' => 'DEPLOYMENT',
            'severity' => 'HIGH',
            'priority' => 'P2',
            'status' => 'NEW',
            'description' => $data['incident']['description'] ?? null,
            'summary' => $data['incident']['title'],
            'source' => 'UpdatePlan',
            'assigned_to' => $plan->operator_id ?? $user,
            'detected_at' => now(),
            'reported_at' => now(),
            'reported_by' => $user,
        ]);
        $assetIds = $plan->assets()->allRelatedIds();
        $components = $plan->components()->get()->mapWithKeys(fn (AssetComponent $component) => [$component->id => ['asset_id' => $component->asset_id]]);
        $incident->updatePlans()->attach($plan->id);
        $incident->assets()->attach($assetIds);
        $incident->components()->attach($components->all());
        $this->audit->log($incident, 'incident_created', [], $incident->attributesToArray() + [
            'asset_ids' => $assetIds->all(), 'component_ids' => $components->keys()->all(), 'plan_ids' => [$plan->id],
        ], $user);
    }

    private function recordRecovery(UpdatePlan $plan, array $data, ?User $actor): void
    {
        $incident = $plan->incidents()->where('source', 'UpdatePlan')->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->latest('incidents.id')->lockForUpdate()->first();
        if (! $incident) {
            return;
        }
        $fields = ['resolution', 'resolution_verification', 'version'];
        $before = $incident->only($fields);
        $incident->update([
            'resolution' => trim($incident->resolution."\n".'ロールバックを実施しました。復元バージョン '.$plan->current_version),
            'resolution_verification' => trim($incident->resolution_verification."\n".$data['restore_verification_result']),
            'version' => $incident->version + 1,
        ]);
        $this->audit->log($incident, 'rollback_recovery_recorded', $before, $incident->only($fields), $actor?->id);
    }
}
