<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\UpdatePlan;
use App\Models\WorkRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePlanTransition
{
    private const FLOW = ['DRAFT' => ['ASSESSMENT', 'CANCELLED'], 'ASSESSMENT' => ['TESTING', 'BLOCKED', 'CANCELLED'], 'TESTING' => ['PENDING_APPROVAL', 'BLOCKED', 'CANCELLED'], 'PENDING_APPROVAL' => ['APPROVED', 'REJECTED', 'BLOCKED', 'CANCELLED'], 'REJECTED' => ['ASSESSMENT'], 'APPROVED' => ['SCHEDULED', 'BLOCKED', 'CANCELLED'], 'SCHEDULED' => ['EXECUTING', 'BLOCKED', 'CANCELLED'], 'EXECUTING' => ['VERIFYING', 'FAILED', 'ROLLBACK_REQUIRED', 'BLOCKED'], 'VERIFYING' => ['COMPLETED', 'ROLLBACK_REQUIRED', 'EMERGENCY_REVIEW', 'FAILED'], 'FAILED' => ['ROLLBACK_REQUIRED'], 'ROLLBACK_REQUIRED' => ['ROLLING_BACK'], 'ROLLING_BACK' => ['ROLLED_BACK', 'ROLLBACK_FAILED'], 'ROLLBACK_FAILED' => [], 'ROLLED_BACK' => [], 'COMPLETED' => [], 'BLOCKED' => [], 'CANCELLED' => [], 'EMERGENCY_REVIEW' => []];

    public function transition(UpdatePlan $plan, string $to, array $data = []): UpdatePlan
    {
        return DB::transaction(function () use ($plan, $to, $data): UpdatePlan {
            $plan = UpdatePlan::query()->lockForUpdate()->findOrFail($plan->id);
            $from = $plan->status;
            if ($from === 'BLOCKED') {
                if ($to !== $plan->blocked_from_status) {
                    throw ValidationException::withMessages(['status' => 'Blocked plans can only resume at their previous status.']);
                }
            } elseif (! in_array($to, self::FLOW[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "Invalid transition: {$from} to {$to}."]);
            }
            $this->guard($plan, $from, $to, $data);
            $plan->status = $to;
            if ($to === 'BLOCKED') {
                $plan->blocked_from_status = $from;
            }
            if ($from === 'BLOCKED') {
                $plan->blocked_from_status = null;
            }
            if ($to === 'EXECUTING') {
                $plan->actual_start_at = now();
                $plan->operator_id = Auth::id();
            }
            if (in_array($to, ['COMPLETED', 'ROLLED_BACK', 'ROLLBACK_FAILED'], true)) {
                $plan->actual_end_at = now();
            }
            if (array_key_exists('completion_result', $data)) {
                $plan->completion_result = $data['completion_result'];
            }
            $plan->save();
            app(AuditLogger::class)->log($plan, 'status_transition', ['status' => $from], ['status' => $to, 'blocked_from_status' => $plan->blocked_from_status]);
            if ($to === 'ROLLBACK_FAILED') {
                $this->incident($plan, $data);
            } elseif ($to === 'ROLLBACK_REQUIRED' && ! empty($data['incident'])) {
                $this->incident($plan, $data);
            }
            if (in_array($to, ['EXECUTING', 'VERIFYING', 'ROLLING_BACK', 'ROLLBACK_REQUIRED', 'ROLLBACK_FAILED', 'ROLLED_BACK', 'COMPLETED'], true)) {
                $this->recordWork($plan, $to, $data);
            }

            return $plan;
        });
    }

    private function guard(UpdatePlan $p, string $from, string $to, array $d): void
    {
        $required = $p->checklistItems()->where('is_required', true)->where(function ($query): void {
            $query->where('status', '!=', 'Passed')->orWhere(function ($waiver): void {
                $waiver->where('status', 'Waived')->where(function ($invalid): void {
                    $invalid->whereNull('waiver_scope')->orWhereNull('exception_reason')->orWhereNull('waived_by');
                });
            });
        })->exists();
        if ($required && in_array($to, ['TESTING', 'PENDING_APPROVAL', 'APPROVED', 'SCHEDULED', 'EXECUTING', 'COMPLETED'], true)) {
            throw ValidationException::withMessages(['checklist' => 'Required checklist items must pass or be waived first.']);
        }
        if ($to === 'TESTING' && empty($p->impact)) {
            throw ValidationException::withMessages(['impact' => 'Impact assessment is required.']);
        }
        if ($to === 'PENDING_APPROVAL' && ! $p->checklistItems()->where('phase', '2 Pre-change Validation')->where('status', 'Passed')->exists()) {
            throw ValidationException::withMessages(['checklist' => 'Test evidence is required.']);
        }
        if ($to === 'APPROVED') {
            if (strtoupper((string) $p->risk_level) === 'HIGH' && ! $p->approvals()->where('decision', 'APPROVED')->exists()) {
                throw ValidationException::withMessages(['approval' => 'Independent reviewer approval is required.']);
            }
            if (! $p->approvals()->where('decision', 'APPROVED')->exists()) {
                throw ValidationException::withMessages(['approval' => 'Approval record is required.']);
            }
        }
        if ($to === 'EXECUTING' && (! $p->backup_verified || ! $p->backup_reference || ! $p->restore_test_passed || ! $p->operator_id || ! $p->planned_start_at || ! $p->planned_end_at || $p->planned_end_at->lessThanOrEqualTo($p->planned_start_at))) {
            throw ValidationException::withMessages(['execution' => 'Backup evidence, restore test, operator, and a valid maintenance window are required.']);
        }
        if ($to === 'EXECUTING' && $from === 'BLOCKED' && ! $p->restore_test_passed) {
            throw ValidationException::withMessages(['restore_test_passed' => 'Restore verification must pass before production execution.']);
        }
        if ($to === 'COMPLETED' && ! $p->checklistItems()->where('phase', '5 Post-change Verification')->where('status', 'Passed')->exists()) {
            throw ValidationException::withMessages(['checklist' => 'Post-change verification evidence is required.']);
        }
        if ($to === 'ROLLED_BACK' && ! $p->checklistItems()->where('phase', '5 Post-change Verification')->where('status', 'Passed')->exists()) {
            throw ValidationException::withMessages(['checklist' => 'Rollback verification evidence is required.']);
        }
        if ($to === 'ROLLED_BACK' && (empty($d['rollback_reason']) || empty($d['restore_verification_result']))) {
            throw ValidationException::withMessages(['rollback' => 'Rollback reason and restore verification result are required.']);
        }
        if ($to === 'EMERGENCY_REVIEW' && empty($d['completion_result'])) {
            throw ValidationException::withMessages(['completion_result' => 'After-action review is required.']);
        }
        if ($to === 'ROLLBACK_FAILED' && empty($d['incident'])) {
            throw ValidationException::withMessages(['incident' => 'Incident details are required.']);
        }
    }

    private function recordWork(UpdatePlan $p, string $status, array $data): void
    {
        $asset = $p->assets()->first();
        if (! $asset || ! Auth::id()) {
            return;
        }
        $incidentId = DB::table('incident_update_plans')->where('update_plan_id', $p->id)->orderByDesc('id')->value('incident_id');
        $details = $data['details'] ?? ($status === 'ROLLED_BACK' ? json_encode(['rollback_reason' => $data['rollback_reason'] ?? null, 'restore_verification_result' => $data['restore_verification_result'] ?? null], JSON_THROW_ON_ERROR) : null);
        WorkRecord::create(['record_code' => 'WR-'.strtoupper(bin2hex(random_bytes(6))), 'record_type' => $status === 'VERIFYING' ? 'Verification' : 'Update', 'asset_id' => $asset->id, 'user_id' => Auth::id(), 'update_plan_id' => $p->id, 'incident_id' => $incidentId, 'summary' => "Update plan {$status}", 'action_summary' => $data['action_summary'] ?? "Recorded {$status} milestone; commands executed externally by operator.", 'details' => $details, 'environment' => $asset->environment ?? 'Unknown', 'result' => $status === 'ROLLBACK_FAILED' ? 'Failed' : 'Pending', 'started_at' => now(), 'performed_at' => now()]);
    }

    private function incident(UpdatePlan $p, array $data): void
    {
        $asset = $p->assets()->firstOrFail();
        $user = Auth::id() ?? $p->created_by;
        $incident = Incident::create(['incident_code' => 'INC-'.strtoupper(bin2hex(random_bytes(6))), 'title' => $data['incident']['title'], 'category' => 'DEPLOYMENT', 'severity' => 'HIGH', 'priority' => 'P2', 'status' => 'NEW', 'description' => $data['incident']['description'] ?? null, 'summary' => $data['incident']['title'], 'source' => 'UpdatePlan', 'assigned_to' => $p->operator_id ?? $user, 'detected_at' => now(), 'reported_at' => now(), 'reported_by' => $user]);
        $incident->updatePlans()->attach($p->id);
        $incident->assets()->attach($asset->id);
        app(AuditLogger::class)->log($incident, 'incident_created', [], $incident->attributesToArray() + ['asset_ids' => [$asset->id], 'plan_ids' => [$p->id]]);
    }
}
