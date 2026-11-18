<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\ChecklistItem;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UpdatePlanTransition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdatePlanController extends Controller
{
    public function index(Request $request)
    {
        $plans = UpdatePlan::with('assets')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->orderByRaw('planned_start_at IS NULL, planned_start_at')->orderBy('plan_code')->get();
        $statusGroups = $plans->groupBy('status');
        $timeGroups = $plans->groupBy(fn (UpdatePlan $plan) => $plan->planned_start_at?->toDateString() ?? '未設定');

        return view('update-plans.index', compact('plans', 'statusGroups', 'timeGroups'));
    }

    public function create()
    {
        $this->authorize('create', UpdatePlan::class);

        return view('update-plans.create', ['assets' => Asset::with('components')->get()]);
    }

    public function store(Request $r, AuditLogger $audit)
    {
        $this->authorize('create', UpdatePlan::class);
        $data = $r->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string', 'asset_ids' => 'required|array|min:1', 'asset_ids.*' => 'exists:assets,id', 'component_ids' => 'array', 'component_ids.*' => 'exists:asset_components,id', 'impact' => 'required|string|max:255', 'risk_level' => 'nullable|string', 'current_version' => 'nullable|string', 'target_version' => 'nullable|string', 'change_type' => 'nullable|in:Standard,Normal,Emergency', 'reason_type' => 'nullable|in:EOL,Security,CVE,Maintenance,Incident']);
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
        $updatePlan->load(['assets', 'components', 'checklistItems', 'approvals.approver', 'incidents', 'operator', 'reviewer', 'sourceAssessment.vulnerability']);
        $operators = Gate::allows('update', $updatePlan)
            ? User::whereHas('roles', fn ($roles) => $roles->where('name', 'Operator'))->orderBy('name')->get()
            : collect();

        return view('update-plans.show', ['plan' => $updatePlan, 'operators' => $operators]);
    }

    public function update(Request $r, UpdatePlan $updatePlan, AuditLogger $audit)
    {
        $this->authorize('update', $updatePlan);
        $d = $r->validate(['impact' => 'nullable|string|max:255', 'test_plan' => 'nullable|string', 'implementation_plan' => 'nullable|string', 'verification_plan' => 'nullable|string', 'rollback_plan' => 'nullable|string', 'rollback_trigger' => 'nullable|string', 'backup_reference' => 'nullable|string', 'backup_verified' => 'nullable|boolean', 'restore_test_passed' => 'nullable|boolean', 'planned_start_at' => 'nullable|date', 'planned_end_at' => 'nullable|date', 'operator_id' => 'nullable|exists:users,id', 'current_version' => 'nullable|string', 'target_version' => 'nullable|string', 'risk_reason' => 'nullable|string']);
        $old = $updatePlan->only(array_keys($d));
        $updatePlan->fill($d)->save();
        $audit->log($updatePlan, 'plan_updated', $old, $d);

        return back();
    }

    public function transition(Request $r, UpdatePlan $updatePlan, UpdatePlanTransition $service)
    {
        $this->authorize('transition', $updatePlan);
        $d = $r->validate([
            'status' => 'required|string',
            'completion_result' => 'nullable|string',
            'action_summary' => 'nullable|string',
            'details' => 'nullable|string',
            'rollback_reason' => 'required_if:status,ROLLED_BACK|nullable|string',
            'restore_verification_result' => 'required_if:status,ROLLED_BACK|nullable|string',
            'incident' => 'nullable|array:title,description',
            'incident.title' => 'required_if:status,ROLLBACK_FAILED|nullable|string|max:255',
            'incident.description' => 'nullable|string',
        ]);
        $service->transition($updatePlan, $d['status'], $d);

        return back();
    }

    public function checklist(Request $r, UpdatePlan $updatePlan, AuditLogger $audit)
    {
        abort_unless($r->user()->roles()->whereIn('name', ['Operator', 'Admin'])->exists(), 403);
        $d = $r->validate(['phase' => ['required', Rule::in(ChecklistItem::PHASES)], 'title' => 'required|string|max:255', 'description' => 'nullable|string', 'expected_result' => 'nullable|string', 'is_required' => 'boolean']);
        $item = $updatePlan->checklistItems()->create($d + ['status' => 'Pending']);
        $audit->log($item, 'created');

        return back();
    }

    public function checklistStatus(Request $r, ChecklistItem $item, AuditLogger $audit)
    {
        if ($r->input('status') === 'Waived') {
            abort_unless($r->user()->roles()->where('name', 'Reviewer')->exists(), 403);
        } else {
            abort_unless($r->user()->roles()->whereIn('name', ['Operator', 'Admin'])->exists(), 403);
        }
        $d = $r->validate(['status' => 'required|in:Passed,Failed,Blocked,Not Applicable,Waived,Pending', 'evidence' => 'nullable|string', 'exception_reason' => 'required_if:status,Waived|nullable|string', 'waiver_scope' => 'required_if:status,Waived|nullable|in:plan,asset,environment', 'correction_reason' => 'nullable|string']);
        if ($item->status === 'Failed' && $d['status'] === 'Passed') {
            $r->validate(['correction_reason' => 'required|string']);
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
        }
        unset($d['correction_reason']);
        $old = $item->only(['status', 'evidence', 'exception_reason', 'waiver_scope']);
        $item->fill($d + ['completed_by' => $r->user()->id, 'completed_at' => now(), 'waived_by' => $d['status'] === 'Waived' ? $r->user()->id : null, 'waived_at' => $d['status'] === 'Waived' ? now() : null])->save();
        $audit->log($item, 'checklist_status', $old, $item->only(['status', 'evidence', 'exception_reason', 'waiver_scope']));

        return back();
    }

    public function approve(Request $r, UpdatePlan $updatePlan, UpdatePlanTransition $service)
    {
        $this->authorize('approve', $updatePlan);
        $d = $r->validate(['decision' => 'required|in:APPROVED,REJECTED', 'comments' => 'nullable|string']);
        $service->approve($updatePlan, $r->user(), $d['decision'], $d['comments'] ?? null);

        return back();
    }
}
