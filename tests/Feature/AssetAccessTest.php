<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AssetAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $u;
    }

    public function test_guests_are_redirected_and_viewers_cannot_write_assets(): void
    {
        $this->get('/assets')->assertRedirect('/login');
        $viewer = $this->userWith('Viewer');
        $this->actingAs($viewer)->post('/assets', ['asset_code' => 'A-1', 'name' => 'A', 'asset_type' => 'Server', 'operational_status' => 'Active'])->assertForbidden();
        $asset = Asset::create(['asset_code' => 'VIEW-1', 'name' => 'Read only', 'asset_type' => 'Server', 'operational_status' => 'Active']);
        $this->put('/assets/'.$asset->id, ['asset_code' => 'VIEW-1', 'name' => 'No edit', 'asset_type' => 'Server', 'operational_status' => 'Active'])->assertForbidden();
        $this->assertSame('Read only', $asset->fresh()->name);
    }

    public function test_operator_can_create_and_update_and_audit_is_written(): void
    {
        $operator = $this->userWith('Operator');
        $this->actingAs($operator)->post('/assets', ['asset_code' => 'A-1', 'name' => 'A', 'asset_type' => 'Server', 'operational_status' => 'Active'])->assertRedirect();
        $asset = Asset::firstOrFail();
        $this->put('/assets/'.$asset->id, ['asset_code' => 'A-1', 'name' => 'Changed', 'asset_type' => 'Server', 'operational_status' => 'Active'])->assertRedirect();
        $this->assertSame(2, AuditEvent::count());
        $this->assertSame($operator->id, AuditEvent::first()->user_id);
    }

    public function test_components_have_immutable_lifecycle_history_and_current_is_latest(): void
    {
        $a = Asset::create(['asset_code' => 'A-1', 'name' => 'A', 'asset_type' => 'Server', 'operational_status' => 'Active']);
        $c = $a->components()->create(['component_type' => 'runtime', 'product_name' => 'PHP', 'support_phase' => 'Unknown']);
        foreach (['2026-01-01', '2026-02-01'] as $date) {
            $c->lifecycleRecords()->create(['product_name' => 'PHP', 'support_phase' => 'Unknown', 'source_name' => 'Manual', 'source_url' => 'https://example.test', 'verified_at' => $date, 'date_precision' => 'DAY', 'data_source_type' => 'OFFICIAL_MANUAL_SEED']);
        }$this->assertCount(2, $c->lifecycleRecords);
        $this->assertSame('2026-02-01 00:00:00', $c->fresh()->currentLifecycle->verified_at->format('Y-m-d H:i:s'));
    }

    public function test_work_records_and_audits_reject_mutation(): void
    {
        $a = Asset::create(['asset_code' => 'A-1', 'name' => 'A', 'asset_type' => 'Server', 'operational_status' => 'Active']);
        $u = User::factory()->create();
        $w = WorkRecord::create(['record_code' => 'W-1', 'record_type' => 'Update', 'asset_id' => $a->id, 'user_id' => $u->id, 'summary' => 'x', 'action_summary' => 'x', 'environment' => 'demo', 'result' => 'Success', 'started_at' => now(), 'performed_at' => now()]);
        try {
            $w->update(['summary' => 'overwritten']);
            self::fail('Work records must reject updates.');
        } catch (\LogicException) {
            self::assertSame('x', $w->fresh()->summary);
        }
        $this->expectException(\LogicException::class);
        $w->delete();
    }

    public function test_duplicate_asset_code_is_rejected_without_persisting(): void
    {
        $operator = $this->userWith('Operator');
        Asset::create(['asset_code' => 'DUP-1', 'name' => 'Existing', 'asset_type' => 'Server', 'operational_status' => 'Active']);
        $this->actingAs($operator)->post('/assets', ['asset_code' => 'DUP-1', 'name' => 'Duplicate', 'asset_type' => 'Server', 'operational_status' => 'Active'])->assertSessionHasErrors('asset_code');
        $this->assertSame(1, Asset::where('asset_code', 'DUP-1')->count());
    }

    public function test_reviewer_is_forbidden_from_asset_create_and_edit_routes(): void
    {
        $reviewer = $this->userWith('Reviewer');
        $asset = Asset::create(['asset_code' => 'REV-1', 'name' => 'Reviewed', 'asset_type' => 'Server', 'operational_status' => 'Active']);
        $this->actingAs($reviewer)->get('/assets/create')->assertForbidden();
        $this->get('/assets/'.$asset->id.'/edit')->assertForbidden();
        $this->post('/assets', ['asset_code' => 'REV-2', 'name' => 'Blocked', 'asset_type' => 'Server', 'operational_status' => 'Active'])->assertForbidden();
        $this->put('/assets/'.$asset->id, ['asset_code' => 'REV-1', 'name' => 'Blocked', 'asset_type' => 'Server', 'operational_status' => 'Active'])->assertForbidden();
    }

    public function test_only_admin_can_manage_user_roles(): void
    {
        foreach (['Operator', 'Reviewer', 'Viewer'] as $role) {
            $user = $this->userWith($role);
            $this->actingAs($user)->get('/settings/users')->assertForbidden();
            $this->put('/settings/users/'.$user->id.'/roles', ['roles' => ['Admin']])->assertForbidden();
            $this->assertFalse($user->roles()->where('name', 'Admin')->exists());
        }
        $admin = $this->userWith('Admin');
        $managed = User::factory()->create();
        $this->actingAs($admin)->get('/settings/users')->assertOk();
        $this->put('/settings/users/'.$managed->id.'/roles', ['roles' => ['Viewer']])->assertRedirect();
        $this->assertTrue($managed->fresh()->roles()->where('name', 'Viewer')->exists());
        $this->put('/settings/users/'.$managed->id.'/roles', ['roles' => []])->assertRedirect();
        $this->assertSame(0, $managed->fresh()->roles()->count());
    }

    public function test_component_edits_are_policy_checked_and_audited(): void
    {
        $operator = $this->userWith('Operator');
        $asset = Asset::create(['asset_code' => 'COMP-1', 'name' => 'Component host', 'asset_type' => 'Server', 'operational_status' => 'Active']);
        $component = $asset->components()->create(['component_type' => 'runtime', 'product_name' => 'PHP', 'support_phase' => 'Unknown']);
        $this->actingAs($operator)->put(route('assets.components.update', [$asset, $component]), ['component_type' => 'database', 'vendor_name' => 'Acme', 'product_name' => 'DB', 'version' => '2.0', 'support_phase' => 'Active'])->assertRedirect();
        $this->assertSame('DB', $component->fresh()->product_name);
        $this->assertSame('updated', AuditEvent::latest('id')->firstOrFail()->event_type);
        $reviewer = $this->userWith('Reviewer');
        $this->actingAs($reviewer)->put(route('assets.components.update', [$asset, $component]), ['component_type' => 'runtime', 'product_name' => 'Denied', 'support_phase' => 'Unknown'])->assertForbidden();
    }

    public function test_support_expiry_and_unverified_states_are_distinct_across_components(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 12:00:00'));
        $this->actingAs($this->userWith('Operator'));
        $asset = Asset::create(['asset_code' => 'ASSET-LIFECYCLE', 'name' => 'Lifecycle inventory', 'asset_type' => 'Server']);
        $url = route('assets.show', $asset);
        $source = ['source_name' => 'PHP Official', 'source_url' => 'https://www.php.net/supported-versions.php', 'date_precision' => 'DAY'];
        foreach ([
            ['PHP', '8.2.30', 'Security Only', '2026-12-30', '2026-12-31'],
            ['Unknown runtime', '1.0', 'Unknown', '2026-12-30', null],
            ['Old snapshot', '2.0', 'Active', '2026-08-01', '2028-12-31'],
        ] as [$name, $version, $phase, $verifiedAt, $eol]) {
            $this->from($url)->post(route('assets.components.store', $asset), [
                'component_type' => 'Runtime', 'product_name' => $name, 'version' => $version, 'support_phase' => $phase,
            ])->assertRedirect($url)->assertSessionHasNoErrors();
            $component = $asset->components()->where('product_name', $name)->sole();
            $this->from($url)->post(route('assets.lifecycle.store', $asset), [
                'component_id' => $component->id, 'verified_at' => $verifiedAt, 'support_phase' => $phase, 'eol_date' => $eol,
            ] + $source)->assertRedirect($url)->assertSessionHasNoErrors();
        }
        $this->assertSame(['8.2.30', '1.0', '2.0'], $asset->components()->orderBy('id')->pluck('version')->all());
        $this->assertArrayNotHasKey('version', $asset->fresh()->getAttributes());
        $this->actingAs($this->userWith('Viewer'))->get($url)->assertOk()
            ->assertSeeInOrder(['PHP 8.2.30', 'ライフサイクル セキュリティ修正のみ・終了間近', 'Unknown runtime 1.0', 'ライフサイクル 終了日不明', 'Old snapshot 2.0', 'ライフサイクル 要再確認'])
            ->assertDontSee('ライフサイクル 通常サポート')
            ->assertSee('href="https://www.php.net/supported-versions.php"', false);
        $this->travel(1)->day();
        $this->get($url)->assertOk()->assertSee('ライフサイクル サポート終了（EOL）');
    }

    public function test_relationship_targets_display_codes_and_names_in_list_and_picker(): void
    {
        $operator = $this->userWith('Operator');
        $source = Asset::create(['asset_code' => 'SRC-1', 'name' => 'Source asset', 'asset_type' => 'Server', 'operational_status' => 'Active']);
        $target = Asset::create(['asset_code' => 'TGT-9', 'name' => 'Target asset', 'asset_type' => 'Database', 'operational_status' => 'Active']);
        $source->relationships()->create(['target_asset_id' => $target->id, 'relationship_type' => 'Depends on']);
        $this->actingAs($operator)->get(route('assets.show', $source))->assertOk()->assertSee('TGT-9')->assertSee('Target asset')->assertSee('TGT-9 ? Target asset');
    }
}
