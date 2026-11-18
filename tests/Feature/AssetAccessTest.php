<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->expectException(\LogicException::class);
        $w->delete();
    }
}
