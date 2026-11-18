<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\AssetRelationship;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('viewAny', Asset::class);
        $q = Asset::query()->with('components.currentLifecycle');
        foreach (['asset_type', 'environment', 'operational_status', 'criticality'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->input($f));
            }
        } if ($r->filled('q')) {
            $q->where(fn ($x) => $x->where('asset_code', 'like', '%'.$r->q.'%')->orWhere('name', 'like', '%'.$r->q.'%'));
        }

        return view('assets.index', ['assets' => $q->paginate(20)]);
    }

    public function create()
    {
        Gate::authorize('create', Asset::class);

        return view('assets.form', ['asset' => new Asset]);
    }

    public function store(Request $r, AuditLogger $audit)
    {
        Gate::authorize('create', Asset::class);
        $d = $r->validate(['asset_code' => 'required|string|max:255|unique:assets', 'name' => 'required|string|max:255', 'asset_type' => 'required|string|max:255', 'environment' => 'nullable|string', 'operational_status' => ['required', Rule::in(['Active', 'Degraded', 'Maintenance', 'Retired'])], 'criticality' => 'nullable|string', 'description' => 'nullable|string', 'vendor' => 'nullable|string', 'support_contact' => 'nullable|string', 'vendor_ticket_ref' => 'nullable|string']);
        $a = Asset::create($d);
        $audit->log($a, 'created', [], $d);

        return redirect()->route('assets.show', $a)->with('status', '登録しました');
    }

    public function show(Asset $asset)
    {
        Gate::authorize('view', $asset);
        $asset->load('components.currentLifecycle', 'components.lifecycleRecords', 'relationships.targetAsset');

        return view('assets.show', ['asset' => $asset, 'targetAssets' => Asset::whereKeyNot($asset->id)->orderBy('asset_code')->get()]);
    }

    public function edit(Asset $asset)
    {
        Gate::authorize('update', $asset);

        return view('assets.form', compact('asset'));
    }

    public function update(Request $r, Asset $asset, AuditLogger $audit)
    {
        Gate::authorize('update', $asset);
        $d = $r->validate(['asset_code' => ['required', 'string', 'max:255', Rule::unique('assets')->ignore($asset->id)], 'name' => 'required|string|max:255', 'asset_type' => 'required|string|max:255', 'environment' => 'nullable|string', 'operational_status' => ['required', Rule::in(['Active', 'Degraded', 'Maintenance', 'Retired'])], 'criticality' => 'nullable|string', 'description' => 'nullable|string', 'vendor' => 'nullable|string', 'support_contact' => 'nullable|string', 'vendor_ticket_ref' => 'nullable|string']);
        $old = $asset->only(array_keys($d));
        $asset->update($d);
        $audit->log($asset, 'updated', $old, $d);

        return redirect()->route('assets.show', $asset);
    }

    public function component(Request $r, Asset $asset, AuditLogger $audit)
    {
        Gate::authorize('update', $asset);
        $d = $r->validate(['component_type' => 'required|string', 'vendor_name' => 'nullable|string', 'product_name' => 'required|string', 'version' => 'nullable|string', 'support_phase' => ['required', Rule::in(['Active', 'Security Only', 'Extended', 'EOL', 'Unknown'])], 'environment' => 'nullable|string']);
        $c = $asset->components()->create($d);
        $audit->log($c, 'created', [], $d);

        return back();
    }

    public function updateComponent(Request $r, Asset $asset, AssetComponent $component, AuditLogger $audit)
    {
        Gate::authorize('update', $asset);
        abort_unless($component->asset_id === $asset->id, 404);
        $d = $r->validate(['component_type' => 'required|string|max:255', 'vendor_name' => 'nullable|string|max:255', 'product_name' => 'required|string|max:255', 'version' => 'nullable|string|max:255', 'support_phase' => ['required', Rule::in(['Active', 'Security Only', 'Extended', 'EOL', 'Unknown'])]]);
        $old = $component->only(array_keys($d));
        $component->update($d);
        $audit->log($component, 'updated', $old, $d);

        return back()->with('status', 'Component updated');
    }

    public function lifecycle(Request $r, Asset $asset)
    {
        Gate::authorize('update', $asset);
        $d = $r->validate(['component_id' => 'required|integer|exists:asset_components,id', 'source_url' => 'required|url', 'source_name' => 'required|string', 'verified_at' => 'required|date', 'support_phase' => ['required', Rule::in(['Active', 'Security Only', 'Extended', 'EOL', 'Unknown'])], 'date_precision' => ['required', Rule::in(['DAY', 'MONTH', 'QUARTER', 'UNKNOWN'])], 'data_source_type' => 'required|string']);
        abort_unless($asset->components()->whereKey($d['component_id'])->exists(), 422);
        $asset->components()->findOrFail($d['component_id'])->lifecycleRecords()->create($d);

        return back();
    }

    public function relationship(Request $r, Asset $asset)
    {
        Gate::authorize('update', $asset);
        $d = $r->validate(['target_asset_id' => 'required|exists:assets,id|different:asset', 'relationship_type' => ['required', Rule::in(['Depends on', 'Runs on', 'Hosted on', 'Connects to'])]]);
        $asset->relationships()->create($d);

        return back();
    }

    public function destroyRelationship(Asset $asset, AssetRelationship $relationship)
    {
        Gate::authorize('update', $asset);
        abort_unless($relationship->source_asset_id === $asset->id, 404);
        $relationship->delete();

        return back();
    }
}
