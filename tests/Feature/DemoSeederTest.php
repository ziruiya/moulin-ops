<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\AuditEvent;
use App\Models\ChecklistItem;
use App\Models\Incident;
use App\Models\LifecycleRecord;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\VulnerabilityComponentAssessment;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function demoPasswords(): array
    {
        $passwords = [];
        foreach (['Admin', 'Operator', 'Reviewer', 'Viewer'] as $role) {
            $passwords[$role] = Str::random(32);
        }
        config(['demo.passwords' => $passwords]);

        return $passwords;
    }

    public function test_running_the_seeder_twice_preserves_counts_and_workflow_history(): void
    {
        $this->demoPasswords();
        $tables = [
            'users', 'roles', 'role_user',
            'assets', 'asset_components', 'asset_relationships', 'component_lifecycle_records',
            'vulnerability_records', 'vulnerability_affected_ranges', 'vulnerability_component_assessments',
            'update_plans', 'update_plan_assets', 'update_plan_components', 'update_checklist_items', 'change_approvals',
            'incidents', 'incident_assets', 'incident_components', 'incident_update_plans', 'work_records', 'audit_events',
        ];
        $this->seed();
        $firstCounts = [];
        foreach ($tables as $table) {
            $firstCounts[$table] = DB::table($table)->count();
        }
        $historyTables = ['users', 'update_plans', 'update_checklist_items', 'change_approvals', 'work_records', 'audit_events'];
        $snapshot = [];
        foreach ($historyTables as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        $this->seed();

        foreach ($firstCounts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        foreach ($snapshot as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->orderBy('id')->get()->all(), $table);
        }
    }

    public function test_frozen_demo_counts_evidence_and_lifecycle_reference_are_present(): void
    {
        $this->demoPasswords();
        $this->seed();
        $this->assertDatabaseCount('assets', 6);
        $this->assertSame(3, Asset::where('asset_type', 'Service')->count());
        $this->assertEqualsCanonicalizing([
            'DEMO-SVC-EC-01', 'DEMO-SVC-INTERNAL-01', 'DEMO-SVC-SALON-01', 'DEMO-EC-WEB-01', 'DEMO-INTERNAL-APP-01', 'DEMO-DB-01',
        ], Asset::pluck('asset_code')->all());
        $this->assertDatabaseCount('asset_components', 18);
        $this->assertDatabaseCount('asset_relationships', 8);
        $this->assertDatabaseCount('component_lifecycle_records', 18);
        $this->assertSame(0, AssetComponent::doesntHave('currentLifecycle')->count());
        $this->assertDatabaseCount('vulnerability_records', 5);
        $this->assertGreaterThanOrEqual(6, DB::table('vulnerability_affected_ranges')->count());
        $this->assertGreaterThanOrEqual(8, VulnerabilityComponentAssessment::count());
        $this->assertDatabaseCount('update_plans', 3);
        $this->assertGreaterThanOrEqual(20, ChecklistItem::count());
        $this->assertGreaterThanOrEqual(3, DB::table('change_approvals')->count());
        $this->assertDatabaseCount('incidents', 2);
        $this->assertGreaterThanOrEqual(12, WorkRecord::count());

        $php = LifecycleRecord::where('product_name', 'PHP')->orderBy('version')->get();
        $this->assertCount(5, $php);
        $this->assertSame([
            ['8.1', 'EOL', '2024-12-31', '2025-12-31'],
            ['8.2', 'Security Only', '2024-12-31', '2026-12-31'],
            ['8.3', 'Security Only', '2025-12-31', '2027-12-31'],
            ['8.4', 'Active', '2026-12-31', '2028-12-31'],
            ['8.5', 'Active', '2027-12-31', '2029-12-31'],
        ], $php->map(fn (LifecycleRecord $record) => [substr($record->version, 0, 3), $record->support_phase, $record->active_support_end, $record->security_support_end])->all());
        foreach ($php as $record) {
            $this->assertSame($record->security_support_end, $record->eol_date);
            $this->assertSame('www.php.net', parse_url($record->source_url, PHP_URL_HOST));
        }
        foreach (LifecycleRecord::all() as $record) {
            $this->assertNotEmpty($record->source_name);
            $this->assertStringStartsWith('https://', $record->source_url);
            $this->assertSame('2026-11-18', $record->verified_at->toDateString());
            $this->assertSame('OFFICIAL_MANUAL_SEED', $record->data_source_type);
            if ($record->support_phase === 'Unknown') {
                $this->assertNull($record->eol_date);
            }
        }

        $this->assertSame([
            'CHG-2026-001' => 'BLOCKED', 'CHG-2026-002' => 'COMPLETED', 'CHG-2026-003' => 'ROLLBACK_FAILED',
        ], UpdatePlan::orderBy('plan_code')->pluck('status', 'plan_code')->all());
        foreach ([
            'CHG-2026-001' => ['ASSESSMENT', 'TESTING', 'PENDING_APPROVAL', 'APPROVED', 'SCHEDULED', 'BLOCKED'],
            'CHG-2026-002' => ['ASSESSMENT', 'TESTING', 'PENDING_APPROVAL', 'APPROVED', 'SCHEDULED', 'EXECUTING', 'VERIFYING', 'COMPLETED'],
            'CHG-2026-003' => ['ASSESSMENT', 'TESTING', 'PENDING_APPROVAL', 'APPROVED', 'SCHEDULED', 'EXECUTING', 'FAILED', 'ROLLBACK_REQUIRED', 'ROLLING_BACK', 'ROLLBACK_FAILED'],
        ] as $code => $states) {
            $plan = UpdatePlan::where('plan_code', $code)->firstOrFail();
            $events = AuditEvent::where(['auditable_id' => $plan->id, 'auditable_type' => UpdatePlan::class])->orderBy('id')->get();
            $this->assertSame($states, $events->where('event_type', 'status_transition')->pluck('new_values.status')->all());
            $this->assertTrue($events->contains('event_type', 'approval_APPROVED'));
        }
        $failed = UpdatePlan::where('plan_code', 'CHG-2026-003')->firstOrFail();
        $incident = $failed->incidents()->sole();
        $this->assertSame('DEMO-INC-2026-001', $incident->incident_code);
        $this->assertDatabaseHas('work_records', ['update_plan_id' => $failed->id, 'incident_id' => $incident->id, 'result' => 'Failed']);
        $this->assertSame(WorkRecord::count(), AuditEvent::where(['auditable_type' => WorkRecord::class, 'event_type' => 'work_record_created'])->count());
        $this->assertSame(Incident::count(), AuditEvent::where(['auditable_type' => Incident::class, 'event_type' => 'incident_created'])->count());
    }

    public function test_core_demo_plan_remains_blocked_and_missing_restore_blocks_execution(): void
    {
        $this->demoPasswords();
        $this->seed();
        $plan = UpdatePlan::where('plan_code', 'CHG-2026-001')->firstOrFail();
        $this->assertSame('BLOCKED', $plan->status);
        $this->assertSame('SCHEDULED', $plan->blocked_from_status);
        $this->assertSame('Normal', $plan->change_type);
        $this->assertSame('Critical', $plan->risk_level);
        $this->assertSame('8.1', $plan->current_version);
        $this->assertSame('8.5.x', $plan->target_version);
        $this->assertSame('demo-operator', $plan->operator->username);
        $this->assertSame('demo-reviewer', $plan->approvals()->sole()->approver->username);
        $this->assertTrue($plan->backup_verified);
        $this->assertFalse($plan->restore_test_passed);
        $this->assertNull($plan->actual_start_at);
        $items = $plan->checklistItems()->orderBy('sequence')->pluck('status', 'sequence');
        $this->assertCount(13, $items);
        $this->assertSame(['Passed', 'Passed', 'Pending'], [$items[6], $items[8], $items[9]]);
        $this->assertSame('8.1.34', $plan->components()->sole()->version);
        $this->assertDatabaseHas('work_records', ['record_code' => 'DEMO-WR-EC-BACKUP', 'update_plan_id' => $plan->id, 'result' => 'Success']);

        $url = route('update-plans.transition', $plan);
        $this->actingAs($plan->operator)->get(route('update-plans.show', $plan))->assertOk()->assertSee('バックアップ 完了 / 復元検証 未確認');
        $this->postJson($url, ['status' => 'EXECUTING'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('BLOCKED', $plan->fresh()->status);
        $this->post($url, ['status' => 'SCHEDULED'])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($url, ['status' => 'EXECUTING'])->assertUnprocessable()->assertJsonValidationErrors('execution');
        $this->assertSame('SCHEDULED', $plan->fresh()->status);
        $this->assertNull($plan->fresh()->actual_start_at);
    }

    public function test_passwords_are_optional_and_the_four_configured_accounts_can_log_in(): void
    {
        config(['demo.passwords' => []]);
        $this->artisan('db:seed')
            ->expectsOutputToContain('DEMO_ADMIN_PASSWORD')
            ->expectsOutputToContain('DEMO_OPERATOR_PASSWORD')
            ->expectsOutputToContain('DEMO_REVIEWER_PASSWORD')
            ->expectsOutputToContain('DEMO_VIEWER_PASSWORD')
            ->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('assets', 6);
        $this->assertDatabaseCount('update_plans', 0);

        $passwords = $this->demoPasswords();
        $this->seed();
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('roles', 4);
        foreach ($passwords as $role => $password) {
            $user = User::where('username', 'demo-'.strtolower($role))->firstOrFail();
            $this->assertTrue(Hash::check($password, $user->password));
            $this->assertNotSame($password, $user->password);
            $this->assertSame([$role], $user->roles()->pluck('name')->all());
            $this->post(route('login.store'), ['login' => $user->username, 'password' => $password])->assertRedirect(route('dashboard'));
            $this->get('/')->assertRedirect(route('dashboard'));
            $this->get(route('dashboard'))->assertOk()->assertSee('DEMO DATA｜想定環境｜実在企業の構成ではありません');
            $this->post(route('logout'))->assertRedirect(route('login'));
        }
        $this->assertDatabaseCount('assets', 6);
        $this->assertDatabaseCount('update_plans', 3);
    }
}
