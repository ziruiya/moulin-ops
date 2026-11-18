<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\AuditEvent;
use App\Models\ChecklistItem;
use App\Models\Incident;
use App\Models\LifecycleRecord;
use App\Models\Role;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\VulnerabilityRecord;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_e2e_01_verified_php_eol_is_visible_and_a_change_reaches_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 13:00:00'));
        $operator = $this->actor('Operator');
        $this->actingAs($operator);
        [$asset, $php] = $this->registerPhp('E2E01-WEB', '8.1.34');
        $detail = route('assets.show', $asset);
        $this->get(route('dashboard'))->assertViewHas('urgentRisks', fn ($risks) => $risks->isEmpty());

        $this->from($detail)->post(route('assets.lifecycle.store', $asset), [
            'component_id' => $php->id,
            'source_name' => 'PHP Official',
            'source_url' => 'https://www.php.net/eol.php',
            'verified_at' => '2026-11-18 12:00:00',
            'support_phase' => 'EOL',
            'date_precision' => 'DAY',
            'active_support_end' => '2024-12-31',
            'security_support_end' => '2025-12-31',
            'eol_date' => '2025-12-31',
            'version' => '8.5.0',
            'data_source_type' => 'AUTOMATIC',
        ])->assertRedirect($detail)->assertSessionHasNoErrors();
        $record = $php->currentLifecycle()->sole();
        $this->assertDatabaseHas('component_lifecycle_records', [
            'id' => $record->id, 'product_name' => 'PHP', 'version' => '8.1.34',
            'data_source_type' => 'OFFICIAL_MANUAL', 'lifecycle_status' => 'EOL',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'auditable_type' => LifecycleRecord::class, 'auditable_id' => $record->id, 'event_type' => 'lifecycle_verified', 'user_id' => $operator->id,
        ]);
        $this->get($detail)->assertOk()->assertSeeInOrder(['PHP 8.1.34', 'サポート終了（EOL）', 'PHP Official', '2026-11-18', '2025-12-31'])
            ->assertSee('https://www.php.net/eol.php')->assertSee('Inventory execution log');
        $this->get(route('dashboard'))->assertOk()->assertSee($detail.'#component-'.$php->id)->assertSee('E2E01-WEB PHP 8.1.34');

        $plan = $this->createPlan($asset, $php, 'E2E-01 PHP EOL', 'EOL');
        $this->transition($plan, 'ASSESSMENT');
        $impact = $this->checklist($plan, 1, 'Web、アプリ、DB 依存を確認');
        $this->postJson(route('update-plans.transition', $plan), ['status' => 'TESTING'])->assertUnprocessable()->assertJsonValidationErrors('checklist');
        $this->put(route('update-plans.update', $plan), [
            'impact' => '公開サービス、アプリケーション、キュー、DB 接続を調査',
            'test_plan' => 'ステージングでログインと DB 接続を確認',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->pass($impact, 'サービス依存と停止影響を台帳で確認');
        $this->transition($plan, 'TESTING');
        $test = $this->checklist($plan, 2, 'ステージングで互換性を検証');
        $this->postJson(route('update-plans.transition', $plan), ['status' => 'PENDING_APPROVAL'])->assertUnprocessable()->assertJsonValidationErrors('checklist');
        $this->pass($test, '検証環境の Web と DB のテストに合格');
        $this->transition($plan, 'PENDING_APPROVAL');

        $this->assertDatabaseHas('update_plan_components', ['update_plan_id' => $plan->id, 'asset_id' => $asset->id, 'component_id' => $php->id]);
        $this->assertSame('公開サービス、アプリケーション、キュー、DB 接続を調査', $plan->fresh()->impact);
        $this->assertSame(['ASSESSMENT', 'TESTING', 'PENDING_APPROVAL'], $this->planStates($plan));
        $this->get(route('update-plans.show', $plan))->assertOk()->assertSee('承認待ち')->assertSee($detail);
    }

    public function test_e2e_02_version_matching_requires_human_evidence_and_links_the_plan(): void
    {
        $operator = $this->actor('Operator');
        $this->actingAs($operator);
        [$asset, $php] = $this->registerPhp('E2E02-AFFECTED', '8.2.10');
        [, $unknown] = $this->registerPhp('E2E02-UNKNOWN', 'vendor-unresolved');
        $this->post(route('vulnerabilities.store'), [
            'vulnerability_code' => 'E2E02-ADVISORY',
            'title' => '架空の PHP 通報',
            'severity' => 'HIGH',
            'cvss_score' => 8.4,
            'exploited_status' => 'UNKNOWN',
            'production_exposed' => true,
            'business_criticality' => 'HIGH',
            'remediation_version' => '8.2.30',
            'source_name' => 'DEMO 仮想通報',
            'source_url' => 'https://advisory.demo.invalid/e2e02',
            'priority_reason' => '本番公開範囲と業務影響を確認',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $vulnerability = VulnerabilityRecord::where('vulnerability_code', 'E2E02-ADVISORY')->sole();
        $detail = route('vulnerabilities.show', $vulnerability);
        $this->from($detail)->post(route('vulnerabilities.ranges.store', $vulnerability), [
            'product_name' => 'PHP',
            'version_scheme' => 'UPSTREAM',
            'affected_version_expression' => '>=8.2.0,<8.2.30',
            'fixed_version' => '8.2.30',
        ])->assertRedirect($detail)->assertSessionHasNoErrors();
        $range = $vulnerability->affectedRanges()->sole();
        $assessmentData = [
            'matched_range_id' => $range->id,
            'assessment_evidence' => '実稼働台帳と検証ログを照合',
            'assessment_reason' => '本番の構成は通報の対象条件に該当',
            'priority_reason' => '公開範囲と修正版を確認',
        ];
        $assessUrl = route('vulnerabilities.assessments.store', $vulnerability);
        $this->from($detail)->post($assessUrl, $assessmentData + ['component_id' => $php->id])->assertRedirect($detail)->assertSessionHasNoErrors();
        $affected = $vulnerability->assessments()->where('component_id', $php->id)->sole();
        $this->assertSame('POTENTIALLY_AFFECTED', $affected->match_status);
        $this->assertSame('8.2.10', $affected->installed_version_snapshot);
        $this->assertSame('バージョン比較：範囲内。', $affected->resolution_note);

        $this->postJson($assessUrl, $assessmentData + ['component_id' => $unknown->id])->assertUnprocessable()->assertJsonValidationErrors('resolution_note');
        $this->from($detail)->post($assessUrl, $assessmentData + ['component_id' => $unknown->id, 'resolution_note' => '配布元版を upstream に対応づけられない。'])->assertRedirect($detail)->assertSessionHasNoErrors();
        $unresolved = $vulnerability->assessments()->where('component_id', $unknown->id)->sole();
        $this->assertSame('RANGE_UNRESOLVED', $unresolved->match_status);
        $this->post(route('vulnerabilities.assessments.update-plan', $unresolved))->assertUnprocessable();
        $this->assertDatabaseCount('update_plans', 0);

        $transitionUrl = route('vulnerabilities.assessments.transition', $affected);
        $this->postJson($transitionUrl, [
            'match_status' => 'CONFIRMED_AFFECTED', 'handling_status' => 'ASSESSING', 'priority_reason' => '本番公開',
        ])->assertUnprocessable()->assertJsonValidationErrors('assessment_evidence');
        $this->post($transitionUrl, $assessmentData + ['match_status' => 'CONFIRMED_AFFECTED', 'handling_status' => 'ASSESSING'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post($transitionUrl, $assessmentData + ['handling_status' => 'ACTION_REQUIRED'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('CONFIRMED_AFFECTED', $affected->fresh()->match_status);

        $response = $this->post(route('vulnerabilities.assessments.update-plan', $affected));
        $plan = $affected->updatePlans()->sole();
        $response->assertRedirect(route('update-plans.show', $plan));
        $this->assertSame('REMEDIATION_PLANNED', $affected->fresh()->handling_status);
        $this->assertDatabaseHas('update_plans', [
            'id' => $plan->id, 'source_assessment_id' => $affected->id, 'reason_type' => 'CVE', 'status' => 'DRAFT', 'target_version' => '8.2.30',
        ]);
        $this->assertDatabaseHas('update_plan_assets', ['update_plan_id' => $plan->id, 'asset_id' => $asset->id]);
        $this->assertDatabaseHas('update_plan_components', ['update_plan_id' => $plan->id, 'asset_id' => $asset->id, 'component_id' => $php->id]);
        $this->assertDatabaseHas('audit_events', ['auditable_type' => UpdatePlan::class, 'auditable_id' => $plan->id, 'event_type' => 'created_from_assessment']);
        $this->get($detail)->assertOk()->assertSee('影響範囲未確定')->assertSee('影響あり')->assertSee(route('update-plans.show', $plan));
        $this->get(route('update-plans.show', $plan))->assertOk()->assertSee($detail.'#assessment-'.$affected->id);
    }

    public function test_e2e_03_http_approval_failure_and_verified_rollback_preserve_linked_evidence(): void
    {
        [$plan, $asset, $component] = $this->failedChange();
        $url = route('update-plans.transition', $plan);
        $this->postJson($url, ['status' => 'COMPLETED'])->assertUnprocessable();
        $this->transition($plan, 'ROLLBACK_REQUIRED', [
            'incident' => ['title' => 'E2E-03 模擬更新失敗', 'description' => '模擬更新後にアプリが起動しない'],
        ]);
        $incident = $plan->incidents()->sole();
        $this->assertDatabaseHas('incident_assets', ['incident_id' => $incident->id, 'asset_id' => $asset->id]);
        $this->assertDatabaseHas('incident_components', ['incident_id' => $incident->id, 'component_id' => $component->id, 'asset_id' => $asset->id]);
        $this->transition($plan, 'ROLLING_BACK');
        $restore = ['status' => 'ROLLED_BACK', 'rollback_reason' => '起動失敗', 'restore_verification_result' => '8.2.30 に復元。Web、アプリ、DB と業務フローを確認。'];
        $this->postJson($url, $restore)->assertUnprocessable()->assertJsonValidationErrors('checklist');
        $this->get(route('update-plans.show', $plan))->assertOk()->assertSee('name="rollback_reason"', false)->assertSee('name="restore_verification_result"', false);
        $this->travel(20)->minutes();
        $this->pass($this->checklist($plan, 5, '原版と業務フローの復元検証'), $restore['restore_verification_result']);
        $this->postJson($url, ['status' => 'ROLLED_BACK'])->assertUnprocessable()->assertJsonValidationErrors(['rollback_reason', 'restore_verification_result']);
        $this->transition($plan, 'ROLLED_BACK', $restore);

        $final = $plan->fresh();
        $this->assertSame($restore['restore_verification_result'], $final->completion_result);
        $this->assertTrue($final->actual_end_at->gt($final->actual_start_at));
        $this->assertSame('8.2.30', $component->fresh()->version);
        $this->assertStringContainsString('8.2.30', $incident->fresh()->resolution);
        $this->assertSame($restore['restore_verification_result'], $incident->fresh()->resolution_verification);
        $work = $plan->workRecords()->where('record_type', 'Restore')->sole();
        $this->assertSame('8.2.30', $work->after_state);
        $this->assertSame('Success', $work->result);
        $this->assertSame($incident->id, $work->incident_id);
        $this->assertSame($asset->id, $work->asset_id);
        $this->assertStringContainsString($restore['rollback_reason'], $work->details);
        $this->assertDatabaseHas('work_records', ['update_plan_id' => $plan->id, 'summary' => 'Update plan FAILED', 'result' => 'Failed']);
        foreach ([
            [Incident::class, $incident->id, 'incident_created'],
            [Incident::class, $incident->id, 'rollback_recovery_recorded'],
            [WorkRecord::class, $work->id, 'work_record_created'],
        ] as [$type, $id, $event]) {
            $this->assertDatabaseHas('audit_events', ['auditable_type' => $type, 'auditable_id' => $id, 'event_type' => $event]);
        }
        $this->assertSame([
            'ASSESSMENT', 'TESTING', 'PENDING_APPROVAL', 'APPROVED', 'SCHEDULED', 'EXECUTING', 'FAILED', 'ROLLBACK_REQUIRED', 'ROLLING_BACK', 'ROLLED_BACK',
        ], $this->planStates($plan));
        $this->get(route('incidents.show', $incident))->assertOk()->assertSee($plan->plan_code)->assertSee('8.2.30');
        $this->get(route('work-records.show', $work))->assertOk()->assertSee($incident->incident_code)->assertSee('8.2.30');
    }

    public function test_e2e_03_01_failed_rollback_and_new_incident_are_atomic_over_http(): void
    {
        [$plan, $asset, $component] = $this->failedChange();
        $this->transition($plan, 'ROLLBACK_REQUIRED');
        $this->transition($plan, 'ROLLING_BACK');
        $before = $plan->fresh()->getRawOriginal();
        $auditBefore = AuditEvent::count();
        $workBefore = WorkRecord::count();
        $request = ['status' => 'ROLLBACK_FAILED', 'incident' => [
            'title' => '模擬復元に失敗', 'description' => '復元後の確認で起動できなかった',
        ]];

        $failNextIncident = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failNextIncident): void {
            if ($failNextIncident && preg_match('/^\s*insert into .incidents./i', $query)) {
                $failNextIncident = false;
                throw new \RuntimeException('Simulated incident creation failure.');
            }
        });
        $this->postJson(route('update-plans.transition', $plan), $request)->assertInternalServerError();
        $this->assertFalse($failNextIncident);
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        foreach (['incidents', 'incident_assets', 'incident_components', 'incident_update_plans'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('audit_events', $auditBefore);
        $this->assertDatabaseCount('work_records', $workBefore);

        $this->travel(30)->minutes();
        $this->transition($plan, 'ROLLBACK_FAILED', $request);
        $incident = $plan->incidents()->sole();
        $this->assertSame('NEW', $incident->status);
        $this->assertSame($request['incident']['title'], $incident->title);
        $this->assertSame($asset->id, $incident->assets()->sole()->id);
        $this->assertSame($component->id, $incident->components()->sole()->id);
        $this->assertSame('8.2.30', $component->fresh()->version);
        $this->assertTrue($plan->fresh()->actual_end_at->gt($plan->fresh()->actual_start_at));
        $this->assertDatabaseHas('work_records', [
            'update_plan_id' => $plan->id, 'incident_id' => $incident->id, 'summary' => 'Update plan ROLLBACK_FAILED', 'result' => 'Failed',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'auditable_type' => Incident::class, 'auditable_id' => $incident->id, 'event_type' => 'incident_created',
        ]);
        $this->assertSame('ROLLBACK_FAILED', last($this->planStates($plan)));
        $this->postJson(route('update-plans.transition', $plan), ['status' => 'COMPLETED'])->assertUnprocessable();
        $this->assertSame('ROLLBACK_FAILED', $plan->fresh()->status);
    }

    private function failedChange(): array
    {
        $this->travelTo(Carbon::parse('2026-11-18 13:00:00'));
        $operator = $this->actor('Operator');
        $reviewer = $this->actor('Reviewer');
        $this->actingAs($operator);
        [$asset, $component] = $this->registerPhp('E2E03-WEB', '8.2.30');
        $plan = $this->createPlan($asset, $component, 'E2E-03 更新と復元', 'Maintenance');
        $this->put(route('update-plans.update', $plan), [
            'test_plan' => 'Web、アプリ、DB と業務フローを検証',
            'implementation_plan' => 'メンテナンス枠で模擬作業',
            'rollback_plan' => '原版と設定を復元',
            'rollback_trigger' => 'アプリケーションの起動不可',
            'backup_reference' => 'DEMO-E2E03-BACKUP',
            'backup_verified' => 1,
            'restore_test_passed' => 1,
            'operator_id' => $operator->id,
            'planned_start_at' => '2026-11-18 13:00:00',
            'planned_end_at' => '2026-11-18 14:00:00',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->transition($plan, 'ASSESSMENT');
        $this->transition($plan, 'TESTING');
        $this->pass($this->checklist($plan, 2, '事前テスト'), '事前検証に合格');
        $this->transition($plan, 'PENDING_APPROVAL');
        $this->actingAs($reviewer)->post(route('update-plans.approve', $plan), [
            'decision' => 'APPROVED', 'comments' => '模擬変更を承認', 'approver_id' => $operator->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('change_approvals', [
            'update_plan_id' => $plan->id, 'approver_id' => $reviewer->id, 'initiated_by' => $operator->id, 'executed_by' => $operator->id,
        ]);

        $this->actingAs($operator);
        $this->transition($plan, 'APPROVED');
        $this->transition($plan, 'SCHEDULED');
        $this->transition($plan, 'EXECUTING', ['action_summary' => '検証用の更新を手動で模擬']);
        $this->transition($plan, 'FAILED', ['details' => '更新後のアプリケーションが起動しない']);

        return [$plan, $asset, $component];
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function registerPhp(string $assetCode, string $version): array
    {
        $this->post(route('assets.store'), [
            'asset_code' => $assetCode, 'name' => $assetCode, 'asset_type' => 'Server', 'environment' => 'Production', 'operational_status' => 'Active',
            'inventory_source' => 'Inventory', 'inventory_status' => 'Verified', 'last_verified_at' => now()->toDateTimeString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $asset = Asset::where('asset_code', $assetCode)->sole();
        $this->from(route('assets.show', $asset))->post(route('assets.components.store', $asset), [
            'component_type' => 'PHP', 'product_name' => 'PHP', 'version' => $version,
            'version_source' => 'Inventory execution log', 'support_phase' => 'Unknown',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return [$asset, $asset->components()->sole()];
    }

    private function createPlan(Asset $asset, AssetComponent $component, string $title, string $reason): UpdatePlan
    {
        $response = $this->post(route('update-plans.store'), [
            'title' => $title, 'asset_ids' => [$asset->id], 'component_ids' => [$component->id],
            'impact' => '本番の Web・アプリ・DB を調査予定', 'risk_level' => 'High',
            'change_type' => 'Normal', 'reason_type' => $reason,
            'current_version' => $component->version, 'target_version' => '8.5.1',
        ]);
        $plan = UpdatePlan::where('title', $title)->sole();
        $response->assertRedirect(route('update-plans.show', $plan))->assertSessionHasNoErrors();

        return $plan;
    }

    private function checklist(UpdatePlan $plan, int $phase, string $title): ChecklistItem
    {
        $this->post(route('update-plans.checklist', $plan), ['phase' => ChecklistItem::PHASES[$phase], 'title' => $title, 'is_required' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        return $plan->checklistItems()->where('title', $title)->sole();
    }

    private function pass(ChecklistItem $item, string $evidence): void
    {
        $this->post(route('checklist-items.status', $item), ['status' => 'Passed', 'evidence' => $evidence])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function transition(UpdatePlan $plan, string $state, array $data = []): void
    {
        $this->from(route('update-plans.show', $plan))->post(route('update-plans.transition', $plan), ['status' => $state] + $data)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($state, $plan->fresh()->status);
    }

    private function planStates(UpdatePlan $plan): array
    {
        return AuditEvent::where(['auditable_type' => UpdatePlan::class, 'auditable_id' => $plan->id, 'event_type' => 'status_transition'])
            ->orderBy('id')->get()->pluck('new_values.status')->all();
    }
}
