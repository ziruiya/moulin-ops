<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Incident;
use App\Models\UpdatePlan;
use App\Models\WorkRecord;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkRecordController extends Controller
{
    private const TYPES = ['Update', 'Backup', 'Restore', 'Incident', 'Troubleshooting', 'Configuration', 'Verification', 'Vendor Communication'];

    public function index(Request $request)
    {
        $records = WorkRecord::with(['asset', 'plan', 'incident', 'user', 'correctionOf'])->when($request->filled('asset_id'), fn ($q) => $q->where('asset_id', $request->integer('asset_id')))->when($request->filled('update_plan_id'), fn ($q) => $q->where('update_plan_id', $request->integer('update_plan_id')))->when($request->filled('incident_id'), fn ($q) => $q->where('incident_id', $request->integer('incident_id')))->orderByDesc('performed_at')->get();
        $assets = Asset::orderBy('asset_code')->get();
        $plans = UpdatePlan::orderBy('plan_code')->get();
        $incidents = Incident::orderBy('incident_code')->get();

        return view('work-records.index', compact('records', 'assets', 'plans', 'incidents'));
    }

    public function store(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->roles()->whereIn('name', ['Admin', 'Operator'])->exists(), 403);
        $data = $request->validate(['record_type' => ['required', Rule::in(self::TYPES)], 'asset_id' => 'required_without:correction_of_id|nullable|exists:assets,id', 'update_plan_id' => 'nullable|exists:update_plans,id', 'incident_id' => 'nullable|exists:incidents,id', 'summary' => 'required|string', 'action_summary' => 'required|string', 'details' => 'nullable|string', 'command_summary' => 'nullable|string', 'error_detail' => 'nullable|string', 'next_action' => 'nullable|string', 'environment' => 'required|string', 'before_state' => 'nullable|string', 'after_state' => 'nullable|string', 'result' => 'required|in:Success,Failed,Partial,Pending', 'evidence_url' => 'nullable|string', 'started_at' => 'required|date', 'ended_at' => 'nullable|date', 'performed_at' => 'required|date', 'correction_of_id' => 'nullable|exists:work_records,id', 'correction_reason' => 'required_with:correction_of_id|nullable|string']);
        $record = DB::transaction(function () use ($data, $request, $audit) {
            if (! empty($data['correction_of_id'])) {
                $original = WorkRecord::findOrFail($data['correction_of_id']);
                $data['asset_id'] = $original->asset_id;
                $data['update_plan_id'] = $original->update_plan_id;
                $data['incident_id'] = $original->incident_id;
                $data['record_type'] = $original->record_type;
                $data['environment'] = $original->environment;
            }
            $row = WorkRecord::create($data + ['record_code' => 'WR-'.strtoupper(bin2hex(random_bytes(4))), 'user_id' => $request->user()->id]);
            $audit->log($row, 'work_record_created', [], ['record_code' => $row->record_code, 'correction_of_id' => $row->correction_of_id]);

            return $row;
        });

        return redirect()->route('work-records.show', $record);
    }

    public function show(WorkRecord $workRecord)
    {
        $workRecord->load(['asset', 'plan', 'incident', 'user', 'correctionOf', 'corrections']);
        $history = DB::table('audit_events')->where('auditable_type', WorkRecord::class)->where('auditable_id', $workRecord->id)->orderBy('created_at')->get();

        return view('work-records.show', compact('workRecord', 'history'));
    }
}
