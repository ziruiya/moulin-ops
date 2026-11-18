<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\Incident;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncidentController extends Controller
{
    private const CATEGORIES = ['AVAILABILITY', 'PERFORMANCE', 'SECURITY', 'DATABASE', 'APPLICATION', 'WEB_SERVER', 'NETWORK', 'BACKUP', 'DEPLOYMENT', 'CONFIGURATION', 'DATA_INTEGRITY', 'THIRD_PARTY', 'OTHER'];

    private const FLOW = ['NEW' => 'TRIAGE', 'TRIAGE' => 'INVESTIGATING', 'INVESTIGATING' => 'MITIGATING', 'MITIGATING' => 'MONITORING', 'MONITORING' => 'RESOLVED', 'RESOLVED' => 'CLOSED', 'REOPENED' => 'INVESTIGATING'];

    public function index(Request $request)
    {
        $incidents = Incident::with(['assets', 'updatePlans', 'assignee'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->latest()->get();

        return view('incidents.index', compact('incidents'));
    }

    public function create()
    {
        $this->authorize('create', Incident::class);

        return view('incidents.create', $this->options() + ['incident' => null]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $this->authorize('create', Incident::class);
        $data = $this->validated($request);
        $incident = DB::transaction(function () use ($data, $request, $audit) {
            $incident = Incident::create($this->attributes($data) + ['incident_code' => 'INC-'.strtoupper(bin2hex(random_bytes(4))), 'reported_by' => $request->user()->id, 'reported_at' => now(), 'status' => 'NEW']);
            $this->syncRelations($incident, $data);
            $audit->log($incident, 'incident_created', [], $incident->attributesToArray() + ['asset_ids' => $data['asset_ids'] ?? [], 'component_ids' => $data['component_ids'] ?? [], 'plan_ids' => $data['plan_ids'] ?? []]);

            return $incident;
        });

        return redirect()->route('incidents.show', $incident);
    }

    public function show(Incident $incident)
    {
        $incident->load(['assets', 'components', 'updatePlans', 'workRecords.user', 'assignee', 'verifier', 'reopenedFrom', 'reopenings']);
        $history = DB::table('audit_events')->where('auditable_type', Incident::class)->where('auditable_id', $incident->id)->orderBy('created_at')->get();

        return view('incidents.show', $this->options() + compact('incident', 'history'));
    }

    public function update(Request $request, Incident $incident, AuditLogger $audit)
    {
        $this->authorize('update', $incident);
        $data = $this->validated($request);
        DB::transaction(function () use ($incident, $data, $request, $audit) {
            $locked = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ((int) $request->input('version') !== $locked->version) {
                abort(409, '他の利用者が更新しました。再読み込みしてください。');
            }
            $old = $locked->only(array_keys($this->attributes($data)));
            $old['asset_ids'] = $locked->assets()->allRelatedIds()->all();
            $old['component_ids'] = $locked->components()->allRelatedIds()->all();
            $old['plan_ids'] = $locked->updatePlans()->allRelatedIds()->all();
            $locked->fill($this->attributes($data));
            $locked->version++;
            $locked->save();
            $this->syncRelations($locked, $data);
            $audit->log($locked, 'incident_updated', $old, $locked->only(array_keys($this->attributes($data))) + ['asset_ids' => $data['asset_ids'] ?? [], 'component_ids' => $data['component_ids'] ?? [], 'plan_ids' => $data['plan_ids'] ?? []]);
        });

        return back()->with('status', '障害情報を更新しました。');
    }

    public function transition(Request $request, Incident $incident, AuditLogger $audit)
    {
        $this->authorize('transition', $incident);
        $data = $request->validate(['version' => 'required|integer', 'status' => 'required|string', 'reopen_reason' => 'required_if:status,REOPENED|nullable|string', 'resolution' => 'nullable|string', 'resolution_verification' => 'nullable|string', 'root_cause' => 'nullable|string', 'closure_reason' => 'required_if:status,CLOSED|nullable|string', 'resolution_verified_by' => 'nullable|exists:users,id', 'follow_up_required' => 'nullable|boolean', 'plan_ids' => 'array', 'plan_ids.*' => 'exists:update_plans,id', 'check_restore' => 'required_if:status,CLOSED|in:1,true,on,yes', 'check_verification' => 'required_if:status,CLOSED|in:1,true,on,yes', 'check_work_record' => 'required_if:status,CLOSED|in:1,true,on,yes', 'check_closure_reason' => 'required_if:status,CLOSED|in:1,true,on,yes', 'check_follow_up' => 'required_if:status,CLOSED|in:1,true,on,yes']);
        $reopened = DB::transaction(function () use ($incident, $data, $request, $audit) {
            $locked = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ((int) $data['version'] !== $locked->version) {
                abort(409, '他の利用者が状態を変更しました。再読み込みしてください。');
            }
            $before = $locked->attributesToArray();
            $target = $data['status'];
            if ($target === 'REOPENED') {
                if ($locked->status !== 'CLOSED') {
                    throw ValidationException::withMessages(['status' => '再開できるのはクローズ済み障害です。']);
                }
                $reopened = $locked->replicate();
                $reopened->fill(['incident_code' => 'INC-'.strtoupper(bin2hex(random_bytes(4))), 'reopened_from_id' => $locked->id, 'status' => 'REOPENED', 'version' => 1, 'detected_at' => now(), 'reported_at' => now(), 'reported_by' => $request->user()->id, 'acknowledged_at' => null, 'started_investigation_at' => null, 'started_at' => null, 'mitigated_at' => null, 'resolved_at' => null, 'closed_at' => null, 'resolution_verified_by' => null, 'resolution_verified_at' => null, 'self_verified' => false, 'root_cause' => null, 'resolution' => null, 'resolution_verification' => null, 'closure_reason' => null]);
                $reopened->save();
                $this->syncRelations($reopened, ['asset_ids' => $locked->assets()->allRelatedIds()->all(), 'component_ids' => $locked->components()->allRelatedIds()->all(), 'plan_ids' => $locked->updatePlans()->allRelatedIds()->all()]);
                $audit->log($locked, 'incident_reopened', $before, $before + ['reason' => $data['reopen_reason'], 'reopened_incident_id' => $reopened->id]);
                $audit->log($reopened, 'incident_reopened', [], $reopened->attributesToArray() + ['reason' => $data['reopen_reason']]);

                return $reopened;
            } elseif (isset(self::FLOW[$locked->status]) && self::FLOW[$locked->status] === $target) {
                $from = $locked->status;
            } elseif (in_array($target, ['ON_HOLD', 'CANCELLED'], true) && ! in_array($locked->status, ['CLOSED', 'CANCELLED'], true)) {
                $from = $locked->status;
            } else {
                throw ValidationException::withMessages(['status' => '許可されていない状態遷移です。']);
            }
            if ($target === 'CLOSED') {
                foreach (['check_restore' => '復旧結果', 'check_verification' => '重要な検証', 'check_work_record' => '作業記録', 'check_closure_reason' => 'クローズ理由', 'check_follow_up' => '後続計画の確認'] as $key => $label) {
                    if (($data[$key] ?? null) !== '1') {
                        throw ValidationException::withMessages([$key => $label.'を確認してください。']);
                    }
                }
                if (! $locked->resolution || ! $locked->resolution_verification || ! $locked->root_cause || ! $data['closure_reason'] || ! $locked->workRecords()->whereNotNull('summary')->whereNotNull('action_summary')->whereNotNull('environment')->whereNotNull('result')->whereNotNull('started_at')->whereNotNull('performed_at')->exists()) {
                    throw ValidationException::withMessages(['status' => '復旧結果・根本原因・検証内容・完全な作業記録・クローズ理由が必要です。']);
                }
                if (! empty($data['follow_up_required']) && empty($data['plan_ids'])) {
                    throw ValidationException::withMessages(['plan_ids' => '必要な後続 UpdatePlan を関連付けてください。']);
                }
                if (in_array($locked->severity, ['CRITICAL', 'HIGH'], true)) {
                    if (empty($data['resolution_verified_by']) || (int) $data['resolution_verified_by'] === (int) $request->user()->id || (int) $data['resolution_verified_by'] === (int) $locked->assigned_to) {
                        throw ValidationException::withMessages(['resolution_verified_by' => '高リスク障害は解決者以外の検証者が必要です。']);
                    }
                    $locked->resolution_verified_by = $data['resolution_verified_by'];
                    $locked->resolution_verified_at = now();
                    $locked->self_verified = false;
                } else {
                    $locked->self_verified = true;
                    $locked->resolution_verified_by = $request->user()->id;
                    $locked->resolution_verified_at = now();
                }
                $locked->closure_reason = $data['closure_reason'];
                $locked->closed_at = now();
                if (! empty($data['plan_ids'])) {
                    $locked->updatePlans()->sync($data['plan_ids']);
                }
            }
            $locked->status = $target;
            if ($target === 'TRIAGE' && ! $locked->acknowledged_at) {
                $locked->acknowledged_at = now();
            }
            if ($target === 'INVESTIGATING' && ! $locked->started_investigation_at) {
                $locked->started_investigation_at = now();
            }
            if (in_array($target, ['TRIAGE', 'INVESTIGATING', 'MITIGATING', 'MONITORING'], true) && ! $locked->started_at) {
                $locked->started_at = now();
            }
            if ($target === 'MITIGATING') {
                $locked->mitigated_at = now();
            }
            if ($target === 'RESOLVED') {
                $locked->resolved_at = now();
            }
            $locked->version++;
            $locked->save();
            $audit->log($locked, $target === 'REOPENED' ? 'incident_reopened' : 'incident_status_changed', $before, $locked->attributesToArray() + ['reason' => $data['reopen_reason'] ?? null, 'closure_checks' => array_intersect_key($data, array_flip(['check_restore', 'check_verification', 'check_work_record', 'check_closure_reason', 'check_follow_up'])), 'plan_ids' => $locked->updatePlans()->allRelatedIds()->all()]);
        });

        if ($reopened instanceof Incident) {
            return redirect()->route('incidents.show', $reopened)->with('status', '原記録を保全し、関連する障害を再開しました。');
        }

        return back()->with('status', '状態を更新し、監査記録を追加しました。');
    }

    private function validated(Request $request): array
    {
        if ($request->isMethod('put')) {
            $request->validate(['version' => 'required|integer|min:1']);
        }

        return $request->validate(['title' => 'required|string|max:255', 'category' => ['required', Rule::in(self::CATEGORIES)], 'severity' => 'required|in:CRITICAL,HIGH,MEDIUM,LOW', 'priority' => 'required|in:P1,P2,P3,P4', 'description' => 'nullable|string', 'summary' => 'required|string', 'user_impact' => 'required|string', 'business_impact' => 'required|string', 'scope_description' => 'required|string', 'detection_method' => 'nullable|string', 'initial_hypothesis' => 'nullable|string', 'workaround' => 'nullable|string', 'recurrence_prevention' => 'nullable|string', 'escalation_reason' => 'nullable|string', 'source' => 'required|string|max:255', 'symptoms' => 'required|string', 'detected_at' => 'required|date', 'assigned_to' => 'required|exists:users,id', 'related_vulnerability_id' => 'nullable|exists:vulnerability_records,id', 'vendor_ticket_ref' => 'nullable|string|max:255', 'web_findings' => 'nullable|string', 'application_findings' => 'nullable|string', 'database_findings' => 'nullable|string', 'network_findings' => 'nullable|string', 'root_cause' => 'nullable|string', 'resolution' => 'nullable|string', 'resolution_verification' => 'nullable|string', 'asset_ids' => 'array', 'asset_ids.*' => 'exists:assets,id', 'component_ids' => 'array', 'component_ids.*' => 'exists:asset_components,id', 'plan_ids' => 'array', 'plan_ids.*' => 'exists:update_plans,id']);
    }

    private function attributes(array $data): array
    {
        $keys = ['title', 'category', 'severity', 'priority', 'summary', 'user_impact', 'business_impact', 'scope_description', 'detection_method', 'initial_hypothesis', 'workaround', 'recurrence_prevention', 'escalation_reason', 'description', 'source', 'symptoms', 'detected_at', 'assigned_to', 'related_vulnerability_id', 'vendor_ticket_ref', 'web_findings', 'application_findings', 'database_findings', 'network_findings', 'root_cause', 'resolution', 'resolution_verification'];

        return array_intersect_key($data, array_flip($keys));
    }

    private function syncRelations(Incident $incident, array $data): void
    {
        $assets = $data['asset_ids'] ?? [];
        $incident->assets()->sync($assets);
        $componentSync = [];
        foreach ($data['component_ids'] ?? [] as $id) {
            $component = AssetComponent::findOrFail($id);
            if (! in_array($component->asset_id, $assets)) {
                throw ValidationException::withMessages(['component_ids' => '関連コンポーネントの資産を選択してください。']);
            }
            $componentSync[$id] = ['asset_id' => $component->asset_id];
        }
        $incident->components()->sync($componentSync);
        $incident->updatePlans()->sync($data['plan_ids'] ?? []);
    }

    private function options(): array
    {
        return ['assets' => Asset::with('components')->orderBy('asset_code')->get(), 'plans' => UpdatePlan::orderBy('plan_code')->get(), 'users' => User::orderBy('name')->get(), 'categories' => self::CATEGORIES];
    }
}
