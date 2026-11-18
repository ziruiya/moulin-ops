<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\ChangeApproval;
use App\Models\ChecklistItem;
use App\Models\UpdatePlan;
use App\Services\AuditLogger;
use App\Services\UpdatePlanTransition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePlanController extends Controller
{
    public function index(Request $request)
    {
        $plans = UpdatePlan::with('assets')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->orderByRaw('planned_start_at IS NULL, planned_start_at')->get();

        return view('update-plans.index', compact('plans'));
    }

    public function create()
    {
        return view('update-plans.create', ['assets' => Asset::with('components')->get()]);
    }

    public function store(Request $r, AuditLogger $audit)
    {
        $data = $r->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string', 'asset_ids' => 'required|array|min:1', 'asset_ids.*' => 'exists:assets,id', 'component_ids' => 'array', 'component_ids.*' => 'exists:asset_components,id', 'impact' => 'required|string', 'risk_level' => 'nullable|string', 'current_version' => 'nullable|string', 'target_version' => 'nullable|string', 'change_type' => 'nullable|string', 'reason_type' => 'nullable|string']);
        $plan = DB::transaction(function () use ($data, $r, $audit) {
            $plan = UpdatePlan::create(['plan_code' => 'CHG-'.strtoupper(bin2hex(random_bytes(4))), 'title' => $data['title'], 'description' => $data['description'] ?? null, 'impact' => $data['impact'], 'risk_level' => $data['risk_level'] ?? null, 'current_version' => $data['current_version'] ?? null, 'target_version' => $data['target_version'] ?? null, 'change_type' => $data['change_type'] ?? null, 'reason_type' => $data['reason_type'] ?? null, 'created_by' => $r->user()->id]);
            $plan->assets()->sync($data['asset_ids']);
            foreach ($data['component_ids'] ?? [] as $id) {
                $c = AssetComponent::findOrFail($id);
                if (! in_array($c->asset_id, $data['asset_ids'])) {
                    throw ValidationException::withMessages(['component_ids' => 'Component does not belong to selected asset.']);
                } DB::table('update_plan_components')->insert(['update_plan_id' => $plan->id, 'component_id' => $id, 'asset_id' => $c->asset_id, 'created_at' => now(), 'updated_at' => now()]);
            } $audit->log($plan, 'created', [], ['plan_code' => $plan->plan_code]);

            return $plan;
        });

        return redirect()->route('update-plans.show', $plan);
    }

    public function show(UpdatePlan $updatePlan)
    {
        $updatePlan->load(['assets', 'components', 'checklistItems', 'approvals']);

        return view('update-plans.show', ['plan' => $updatePlan]);
    }

    public function update(Request $r, UpdatePlan $updatePlan, AuditLogger $audit)
    {
        $this->authorize('update', $updatePlan);
        $d = $r->validate(['test_plan' => 'nullable|string', 'implementation_plan' => 'nullable|string', 'verification_plan' => 'nullable|string', 'rollback_plan' => 'nullable|string', 'rollback_trigger' => 'nullable|string', 'backup_reference' => 'nullable|string', 'backup_verified' => 'nullable|boolean', 'restore_test_passed' => 'nullable|boolean', 'planned_start_at' => 'nullable|date', 'planned_end_at' => 'nullable|date', 'operator_id' => 'nullable|exists:users,id', 'current_version' => 'nullable|string', 'target_version' => 'nullable|string', 'risk_reason' => 'nullable|string']);
        $old = $updatePlan->only(array_keys($d));
        $updatePlan->fill($d)->save();
        $audit->log($updatePlan, 'plan_updated', $old, $d);

        return back();
    }

    public function transition(Request $r, UpdatePlan $updatePlan, UpdatePlanTransition $service)
    {
        $this->authorize('transition', $updatePlan);
        $d = $r->validate(['status' => 'required|string', 'completion_result' => 'nullable|string', 'action_summary' => 'nullable|string', 'details' => 'nullable|string', 'incident.title' => 'required_if:status,ROLLBACK_FAILED|string', 'incident.description' => 'nullable|string']);
        $service->transition($updatePlan, $d['status'], $d);

        return back();
    }

    public function checklist(Request $r, UpdatePlan $updatePlan, AuditLogger $audit)
    {
        abort_unless($r->user()->roles()->whereIn('name', ['Operator', 'Admin'])->exists(), 403);
        $d = $r->validate(['phase' => 'required|string', 'title' => 'required|string', 'description' => 'nullable|string', 'expected_result' => 'nullable|string', 'is_required' => 'boolean']);
        $item = $updatePlan->checklistItems()->create($d + ['status' => 'Pending']);
        $audit->log($item, 'created');

        return back();
    }

    public function checklistStatus(Request $r, ChecklistItem $item, AuditLogger $audit)
    {
        abort_unless($r->user()->roles()->whereIn('name', ['Operator', 'Admin', 'Reviewer'])->exists(), 403);
        $d = $r->validate(['status' => 'required|in:Passed,Failed,Blocked,Not Applicable,Waived,Pending', 'evidence' => 'nullable|string', 'exception_reason' => 'nullable|string', 'waiver_scope' => 'required_if:status,Waived|nullable|in:plan,asset,environment', 'correction_reason' => 'required_if:status,Passed|nullable|string']);
        if ($item->status === 'Failed' && $d['status'] === 'Passed') {
            $correction = $item->plan->checklistItems()->create([
                'phase' => $item->phase,
                'sequence' => $item->sequence + 1,
                'title' => $item->title.'（項目 #'.$item->id.' の是正）',
                'description' => $d['correction_reason'],
                'is_required' => $item->is_required,
                'status' => 'Passed',
                'evidence' => $d['evidence'] ?? null,
                'completed_by' => $r->user()->id,
                'completed_at' => now(),
            ]);
            $audit->log($correction, 'checklist_correction_recorded', ['failed_item_id' => $item->id], ['correction_reason' => $d['correction_reason']]);

            return back()->with('status', '是正記録を追加しました。Failed の元項目は保持されています。');
        } if ($d['status'] === 'Waived' && (! $r->user()->roles()->where('name', 'Reviewer')->exists() || ! $d['exception_reason'] || ! $d['waiver_scope'])) {
            throw ValidationException::withMessages(['waiver' => 'Waiver reason, scope, and authorizer are required.']);
        } $old = $item->only(['status', 'evidence', 'exception_reason', 'waiver_scope']);
        $item->fill($d + ['completed_by' => $r->user()->id, 'completed_at' => now(), 'waived_by' => $d['status'] === 'Waived' ? $r->user()->id : null, 'waived_at' => $d['status'] === 'Waived' ? now() : null])->save();
        $audit->log($item, 'checklist_status', $old, $item->only(['status', 'evidence', 'exception_reason', 'waiver_scope']));

        return back();
    }

    public function approve(Request $r, UpdatePlan $updatePlan, AuditLogger $audit)
    {
        $this->authorize('approve', $updatePlan);
        $d = $r->validate(['decision' => 'required|in:APPROVED,REJECTED', 'comments' => 'nullable|string']);
        if ($updatePlan->approvals()->where('decision', 'APPROVED')->exists()) {
            throw ValidationException::withMessages(['decision' => 'Approval decisions are immutable; create a new submission after rejection.']);
        } ChangeApproval::create(['update_plan_id' => $updatePlan->id, 'initiated_by' => $updatePlan->created_by, 'approver_id' => $r->user()->id, 'executed_by' => $updatePlan->operator_id, 'decision' => $d['decision'], 'comments' => $d['comments'] ?? null, 'decided_at' => now()]);
        $audit->log($updatePlan, 'approval_'.$d['decision']);

        return back();
    }
}
