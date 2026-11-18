<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\Incident;
use App\Models\Role;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\VulnerabilityComponentAssessment;
use App\Models\VulnerabilityRecord;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $viewer = User::factory()->create();
        $viewer->roles()->attach(Role::firstOrCreate(['name' => 'Viewer']));

        return $viewer;
    }

    public function test_empty_dashboard_guides_initial_inventory_in_order(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs($this->viewer())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('運用ダッシュボード')
            ->assertSee('DEMO DATA｜想定環境｜実在企業の構成ではありません')
            ->assertSeeInOrder([
                '初期棚卸し', '業務サービスを確認する', 'IT資産とコンポーネントを棚卸しする',
                'サービスと資産の依存関係を確認する', 'バックアップと復元を検証する', 'ライフサイクルを公式情報で確認する',
                '責任者とベンダーの連絡先を確認する', '最初のリスク対応計画を作る',
            ])
            ->assertDontSee('緊急リスク')
            ->assertViewHas('urgentRisks', fn ($risks) => $risks->isEmpty());
    }

    public function test_dashboard_partitions_current_risks_and_prioritizes_actionable_evidence(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 15:00:00', 'Asia/Tokyo'));
        $viewer = $this->viewer();
        $public = $this->asset('PUBLIC', ['criticality' => 'Critical', 'internet_exposed' => true]);
        $internal = $this->asset('INTERNAL', ['criticality' => 'Medium']);
        $staging = $this->asset('STAGING', ['environment' => 'Staging']);
        $unverified = $this->asset('UNVERIFIED', ['inventory_source' => null, 'inventory_status' => 'Unverified', 'last_verified_at' => null]);
        $live = $this->inventoryComponent($public, 'PHP', '2025-12-31');
        $internalEol = $this->inventoryComponent($internal, 'PHP', '2024-12-31');
        $this->inventoryComponent($staging, 'PHP', '2025-12-31');
        $stale = $this->inventoryComponent($public, 'Stale runtime', '2024-12-31', ['verified_at' => now()->subDays(100)]);
        $changed = $this->inventoryComponent($unverified, 'Changed runtime', '2024-12-31', ['version' => '7.1'], null);

        $confirmed = $this->vulnerability($live, $viewer, 'CONFIRMED', 'CONFIRMED_AFFECTED', exploited: 'CONFIRMED');
        $potential = $this->vulnerability($live, $viewer, 'POTENTIAL', 'POTENTIALLY_AFFECTED');
        $this->vulnerability($live, $viewer, 'MITIGATED', 'CONFIRMED_AFFECTED', 'MITIGATED');
        $this->vulnerability($live, $viewer, 'LOW', 'CONFIRMED_AFFECTED', severity: 'LOW');
        $superseded = $this->vulnerability($live, $viewer, 'SUPERSEDED', 'CONFIRMED_AFFECTED');
        $this->assessment($superseded, $live, $viewer, 'CONFIRMED_NOT_AFFECTED', supersedes: $superseded->assessments()->sole()->id);

        $this->workRecord($public, $viewer, 'RESTORE-OLD', 'Restore', 'Success', 100);
        $failedRestore = $this->workRecord($public, $viewer, 'RESTORE-FAILED', 'Restore', 'Failed', 5);
        $this->workRecord($public, $viewer, 'BACKUP-NEW', 'Backup', 'Success', 1);
        $staleRestore = $this->workRecord($internal, $viewer, 'RESTORE-STALE', 'Restore', 'Success', 120);
        $this->workRecord($internal, $viewer, 'RESTORE-RECORDED-LATE', 'Restore', 'Failed', 130);

        $review = $this->plan($viewer, 'REVIEW', 'PENDING_APPROVAL');
        $approved = $this->plan($viewer, 'APPROVED', 'APPROVED', [
            'planned_start_at' => '2026-11-18 16:00:00', 'planned_end_at' => '2026-11-18 17:00:00',
        ]);
        $maintenance = $this->plan($viewer, 'MAINTENANCE', 'SCHEDULED', [
            'planned_start_at' => '2026-11-17 14:45:00', 'planned_end_at' => '2026-11-17 15:30:00',
        ]);
        $blocked = $this->plan($viewer, 'BLOCKED', 'BLOCKED', ['blocked_from_status' => 'SCHEDULED']);
        $acceptance = $this->plan($viewer, 'ACCEPTANCE', 'VERIFYING');
        $this->plan($viewer, 'COMPLETE', 'COMPLETED', [
            'planned_start_at' => '2026-11-18 01:00:00', 'planned_end_at' => '2026-11-18 02:00:00',
        ]);
        $this->plan($viewer, 'DRAFT', 'DRAFT');
        $open = $this->incident($viewer, 'OPEN', 'INVESTIGATING', 'P1');
        $this->incident($viewer, 'CLOSED', 'CLOSED', 'P1');

        $response = $this->actingAs($viewer)->get(route('dashboard'));
        $response->assertOk()->assertDontSee('初期棚卸し')
            ->assertSeeInOrder(['緊急リスク', 'データ要確認', 'バックアップと復元', '変更管理', '今日の作業リスト']);
        $assessment = $confirmed->assessments()->sole();
        $this->assertSame([
            route('vulnerabilities.show', $confirmed).'#assessment-'.$assessment->id,
            route('assets.show', $public).'#component-'.$live->id,
            route('assets.show', $internal).'#component-'.$internalEol->id,
        ], $response->viewData('urgentRisks')->pluck('url')->all());
        $response->assertSee(route('vulnerabilities.show', $confirmed).'#assessment-'.$assessment->id)
            ->assertSee(route('assets.show', $public).'#component-'.$live->id)
            ->assertSee(route('work-records.show', $failedRestore))
            ->assertSee(route('update-plans.show', $review))
            ->assertSee(route('incidents.show', $open));

        $data = $response->viewData('dataChecks')->keyBy('url');
        $this->assertCount(3, $data);
        $this->assertStringContainsString('棚卸しの情報源が不明', $data[route('assets.show', $unverified)]['detail']);
        $this->assertStringContainsString('ライフサイクル情報が更新期限超過', $data[route('assets.show', $public).'#component-'.$stale->id]['detail']);
        $this->assertStringContainsString('ライフサイクルが未確認', $data[route('assets.show', $unverified).'#component-'.$changed->id]['detail']);

        $restore = $response->viewData('restoreChecks')->keyBy('url');
        $this->assertSame(route('work-records.show', $failedRestore), $restore->keys()->first());
        $this->assertStringContainsString('復元テスト失敗', $restore[route('work-records.show', $failedRestore)]['detail']);
        $this->assertStringContainsString('再検証期限超過', $restore[route('work-records.show', $staleRestore)]['detail']);
        $this->assertSame('復元未検証', $restore[route('assets.show', $unverified)]['detail']);
        $this->assertSame([
            route('update-plans.show', $review), route('update-plans.show', $maintenance),
            route('update-plans.show', $approved), route('update-plans.show', $blocked), route('update-plans.show', $acceptance),
        ], $response->viewData('changes')->pluck('url')->all());
        $this->assertSame([$open->id], $response->viewData('openIncidents')->modelKeys());
        $this->assertSame(['CONFIRMED', $potential->vulnerability_code, 'LOW'], $response->viewData('pendingVulnerabilities')->pluck('vulnerability_code')->all());
        $this->assertSame([$maintenance->id], $response->viewData('maintenanceWindows')->modelKeys());
        $response->assertSee('11/17 23:45–11/18 00:30');
    }

    private function asset(string $code, array $fields = []): Asset
    {
        return Asset::create($fields + [
            'asset_code' => $code, 'name' => $code, 'asset_type' => 'Server', 'environment' => 'Production',
            'inventory_source' => 'Inventory log', 'inventory_status' => 'Verified', 'last_verified_at' => now(),
        ]);
    }

    private function inventoryComponent(Asset $asset, string $product, string $eol, array $record = [], ?string $source = 'Install report'): AssetComponent
    {
        $component = $asset->components()->create(['component_type' => 'Runtime', 'product_name' => $product, 'version' => '8.1.34', 'version_source' => $source]);
        $component->lifecycleRecords()->create($record + [
            'product_name' => $product, 'version' => $component->version, 'support_phase' => 'Active', 'eol_date' => $eol,
            'source_name' => 'PHP Group', 'source_url' => 'https://www.php.net/eol.php', 'verified_at' => now(),
        ]);

        return $component;
    }

    private function vulnerability(AssetComponent $component, User $user, string $code, string $match, string $handling = 'ACTION_REQUIRED', string $exploited = 'NOT_KNOWN', string $severity = 'HIGH'): VulnerabilityRecord
    {
        $vulnerability = VulnerabilityRecord::create(['vulnerability_code' => $code, 'title' => $code, 'severity' => $severity, 'exploited_status' => $exploited]);
        $this->assessment($vulnerability, $component, $user, $match, $handling);

        return $vulnerability;
    }

    private function assessment(VulnerabilityRecord $vulnerability, AssetComponent $component, User $user, string $match, string $handling = 'ACTION_REQUIRED', ?int $supersedes = null): VulnerabilityComponentAssessment
    {
        return $vulnerability->assessments()->create([
            'component_id' => $component->id, 'asset_id' => $component->asset_id, 'match_status' => $match, 'handling_status' => $handling,
            'supersedes_assessment_id' => $supersedes, 'installed_version_snapshot' => $component->version,
            'assessment_method' => 'MANUAL_VERIFIED', 'assessment_evidence' => 'Evidence', 'assessment_reason' => 'Reviewed',
            'assessed_by' => $user->id, 'assessed_at' => now(),
        ]);
    }

    private function workRecord(Asset $asset, User $user, string $code, string $type, string $result, int $daysAgo): WorkRecord
    {
        return $asset->workRecords()->create([
            'record_code' => $code, 'record_type' => $type, 'summary' => $code, 'action_summary' => 'Recorded',
            'result' => $result, 'environment' => $asset->environment, 'user_id' => $user->id,
            'started_at' => now()->subDays($daysAgo), 'performed_at' => now()->subDays($daysAgo),
        ]);
    }

    private function plan(User $user, string $code, string $status, array $fields = []): UpdatePlan
    {
        return UpdatePlan::create($fields + ['plan_code' => $code, 'title' => $code, 'status' => $status, 'created_by' => $user->id]);
    }

    private function incident(User $user, string $code, string $status, string $priority): Incident
    {
        return Incident::create([
            'incident_code' => $code, 'title' => $code, 'category' => 'APPLICATION', 'severity' => 'LOW',
            'priority' => $priority, 'status' => $status, 'reported_by' => $user->id, 'detected_at' => now(),
        ]);
    }
}
