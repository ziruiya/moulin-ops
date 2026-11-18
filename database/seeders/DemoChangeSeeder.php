<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\ChecklistItem;
use App\Models\Incident;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\WorkRecord;
use App\Services\AuditLogger;
use App\Services\UpdatePlanTransition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

class DemoChangeSeeder
{
    public function __construct(private AuditLogger $audit, private UpdatePlanTransition $workflow) {}

    public function run(array $assets, User $operator, User $reviewer): void
    {
        $blocked = $this->plan(
            'CHG-2026-001', '公開EC PHP 8.1 の更新評価', $assets[DemoInventorySeeder::EC_WEB], $assets[DemoInventorySeeder::EC_SERVICE],
            'PHP', '8.1', '8.5.x', '2026-11-18 22:00', 'EOL', 'Critical', $operator, $reviewer,
        );
        $completed = $this->plan(
            'CHG-2026-002', 'サロン支援 PHP の保守更新', $assets[DemoInventorySeeder::SALON_SERVICE], null,
            'PHP', '8.5.1', '8.5.2', '2026-11-16 21:00', 'Maintenance', 'Medium', $operator, $reviewer,
        );
        $failed = $this->plan(
            'CHG-2026-003', '社内業務 PHP の更新とロールバック障害', $assets[DemoInventorySeeder::INTERNAL_APP], $assets[DemoInventorySeeder::INTERNAL_SERVICE],
            'PHP', '8.2.30', '8.5.1', '2026-11-17 21:00', 'Maintenance', 'High', $operator, $reviewer,
        );

        Date::setTestNow(DemoSeeder::VERIFIED_AT);
        $incident = Incident::firstOrCreate(['incident_code' => 'DEMO-INC-2026-002'], [
            'title' => '公開EC 復元リハーサルで整合性検証に失敗',
            'summary' => 'DEMO｜隔離環境に復元した模擬データの照合結果が一致しませんでした。',
            'category' => 'BACKUP',
            'severity' => 'MEDIUM',
            'priority' => 'P1',
            'status' => 'NEW',
            'source' => 'DEMO Restore Rehearsal',
            'detected_at' => '2026-11-05 02:00:00',
            'reported_at' => '2026-11-05 02:15:00',
            'reported_by' => $operator->id,
            'assigned_to' => $operator->id,
        ]);
        if ($incident->wasRecentlyCreated) {
            $ecWeb = $assets[DemoInventorySeeder::EC_WEB];
            $incident->assets()->attach($ecWeb->id);
            $incident->updatePlans()->attach($blocked->id);
            $this->audit->log($incident, 'incident_created', [], $incident->attributesToArray() + [
                'asset_ids' => [$ecWeb->id], 'plan_ids' => [$blocked->id],
            ], $operator->id);
        }

        $this->work($assets[DemoInventorySeeder::EC_WEB], $operator, 'EC-BACKUP', 'Backup', '2026-11-18 11:15:00', '本番バックアップ取得完了 / DEMO-BKP-001', 'Success', $blocked);
        $this->work($assets[DemoInventorySeeder::EC_WEB], $operator, 'EC-RESTORE', 'Restore', '2026-11-05 02:00:00', '前回の隔離復元テストでデータ照合に失敗', 'Failed', $blocked, $incident);
        $this->work($assets[DemoInventorySeeder::EC_WEB], $operator, 'EC-INVESTIGATION', 'Troubleshooting', '2026-11-05 03:00:00', '復元データと構成の差分を調査', 'Partial', $blocked, $incident);
        $this->work($assets[DemoInventorySeeder::EC_WEB], $operator, 'EC-PRETEST', 'Verification', '2026-11-18 07:00:00', '検証環境で PHP 更新候補と EC の基本動作を確認', 'Success', $blocked);
        $this->work($assets[DemoInventorySeeder::DATABASE], $operator, 'DB-RESTORE', 'Restore', '2026-08-01 02:00:00', '隔離環境で復元とデータ照合が完了', 'Success');
        $this->work($assets[DemoInventorySeeder::SALON_SERVICE], $operator, 'SALON-RESTORE', 'Restore', '2026-11-16 10:00:00', 'サロン支援のデータと設定の復元を検証', 'Success', $completed);
        $this->work($assets[DemoInventorySeeder::INTERNAL_APP], $operator, 'INTERNAL-RESTORE', 'Restore', '2026-11-17 10:10:00', '実施前に隔離環境のバックアップ復元を検証', 'Success', $failed);
    }

    private function plan(string $code, string $title, Asset $asset, ?Asset $service, string $product, string $current, string $target, string $scheduled, string $reason, string $risk, User $operator, User $reviewer): UpdatePlan
    {
        $start = Carbon::parse($scheduled, 'Asia/Tokyo')->utc();
        Date::setTestNow($start->copy()->subHours(8));
        $blocked = $code === 'CHG-2026-001';
        $plan = UpdatePlan::firstOrCreate(['plan_code' => $code], [
            'title' => $title,
            'description' => 'DEMO｜隔離された想定環境の作業記録。',
            'change_type' => $code === 'CHG-2026-002' ? 'Standard' : 'Normal',
            'status' => 'DRAFT',
            'reason_type' => $reason,
            'reason_detail' => $blocked ? 'PHP 8.1 の公式セキュリティサポートが終了。互換性確認後に更新先の版を確定する。' : 'PHP ランタイムの保守作業を模擬します。',
            'impact' => $asset->name.'の Web・アプリケーション・DB 接続と関連業務フローを確認。',
            'risk_level' => $risk,
            'risk_reason' => $blocked ? '公開本番サービスへの影響が大きく、現行 PHP はサポート終了。復元検証が実施条件。' : '業務影響とバックアップからの復旧時間を評価。',
            'current_version' => $current,
            'target_version' => $target,
            'patch_source_url' => 'https://www.php.net/manual/en/migration85.php',
            'compatibility_notes' => 'フレームワーク、Composer 依存、PHP 拡張と構成を検証対象とする。',
            'test_plan' => '検証環境で互換性、性能、ログイン、検索と模擬業務フローを確認する。',
            'implementation_plan' => '担当者がメンテナンス枠内に隔離環境で作業し、結果を記録する。',
            'verification_plan' => 'Web 応答、PHP-FPM、アプリ起動、DB 接続、ログ、キューと主要業務フローを確認する。',
            'rollback_plan' => '適用前のランタイムと設定を復元し、Web・アプリケーション・DB と業務フローを照合する。',
            'rollback_trigger' => 'アプリが起動しない、DB 接続に失敗する、または主要業務を実行できない場合。',
            'backup_verified' => false,
            'restore_test_passed' => false,
            'planned_start_at' => $start,
            'planned_end_at' => $start->copy()->addHour(),
            'operator_id' => $operator->id,
            'reviewer_id' => $reviewer->id,
            'created_by' => $operator->id,
        ]);
        if (! $plan->wasRecentlyCreated) {
            return $plan;
        }
        $component = $asset->components()->where('product_name', $product)->firstOrFail();
        $assetIds = array_values(array_filter([$asset->id, $service?->id]));
        $plan->assets()->attach($assetIds);
        $plan->components()->attach($component->id, ['asset_id' => $asset->id]);
        $this->audit->log($plan, 'created', [], ['plan_code' => $code, 'status' => $plan->status, 'asset_ids' => $assetIds, 'component_id' => $component->id], $operator->id);
        $items = $this->checklist($plan, $blocked, $operator);

        $this->workflow->transition($plan, 'ASSESSMENT', actor: $operator);
        $this->passPhase($items, 1, $operator);
        $this->workflow->transition($plan, 'TESTING', actor: $operator);
        Date::setTestNow($start->copy()->subHours(6));
        $this->passPhase($items, 2, $operator);
        $this->workflow->transition($plan, 'PENDING_APPROVAL', actor: $operator);

        Date::setTestNow($start->copy()->subMinutes(100));
        $backup = [
            'backup_reference' => 'DEMO-BKP-'.substr($code, -3),
            'backup_verified' => true,
            'restore_test_passed' => ! $blocked,
        ];
        $old = $plan->only(array_keys($backup));
        $plan->update($backup);
        $this->audit->log($plan, 'plan_updated', $old, $backup, $operator->id);
        foreach ($items as $item) {
            if ($item->phase === ChecklistItem::PHASES[3] && (! $blocked || $item->sequence === 8)) {
                $this->check($item, 'Passed', $item->expected_result, $operator);
            }
        }

        Date::setTestNow($start->copy()->subMinutes(30));
        $this->workflow->approve($plan, $reviewer, 'APPROVED', $blocked
            ? '計画を承認。復元検証に合格するまで本番実施不可。'
            : '影響範囲、事前検証、バックアップと復元検証を確認。');
        $this->workflow->transition($plan, 'APPROVED', actor: $operator);
        $this->workflow->transition($plan, 'SCHEDULED', actor: $operator);
        if ($blocked) {
            return $this->workflow->transition($plan, 'BLOCKED', actor: $operator);
        }

        Date::setTestNow($start);
        $this->advance($plan, 'EXECUTING', $operator, ['action_summary' => 'メンテナンス枠に模擬作業を開始。']);
        Date::setTestNow($start->copy()->addMinutes(20));
        if ($code === 'CHG-2026-002') {
            $this->passPhase($items, 4, $operator);
            $this->advance($plan, 'VERIFYING', $operator);
            Date::setTestNow($start->copy()->addMinutes(40));
            $this->passPhase($items, 5, $operator);

            return $this->advance($plan, 'COMPLETED', $operator, ['completion_result' => 'PHP 8.5.2 と業務フローの動作確認を完了。']);
        }

        $this->check($items[5], 'Failed', '模擬アプリケーションが更新後に起動しない。', $operator);
        $this->advance($plan, 'FAILED', $operator, ['details' => '模擬更新後のアプリケーション起動に失敗。']);
        $this->advance($plan, 'ROLLBACK_REQUIRED', $operator);
        Date::setTestNow($start->copy()->addMinutes(30));
        $this->advance($plan, 'ROLLING_BACK', $operator);
        Date::setTestNow($start->copy()->addMinutes(50));
        $this->check($items[6], 'Failed', '旧バージョンを戻した後も疎通確認に失敗。', $operator);

        return $this->advance($plan, 'ROLLBACK_FAILED', $operator, [
            'incident_code' => 'DEMO-INC-2026-001',
            'incident' => ['title' => '社内業務 PHP 更新後の復旧失敗', 'description' => 'DEMO｜旧ランタイム復元後もアプリ疎通が回復せず、障害対応へ引き継ぎ。'],
            'completion_result' => '更新失敗後のロールバックでも稼働確認に失敗。関連障害で対応中。',
        ]);
    }

    /** @return array<int, ChecklistItem> */
    private function checklist(UpdatePlan $plan, bool $blocked, User $operator): array
    {
        $rows = $blocked
            ? [
                [1, '資産と実稼働 PHP バージョンを確認', '稼働台帳で PHP 8.1.34 と PHP-FPM を確認。'],
                [1, 'サービスと依存先の影響を調査', '公開EC、Web、アプリ、共用 DB の依存関係を確認。'],
                [1, '互換性を調査', 'フレームワーク、拡張、構成、依存パッケージの対象範囲を確定。'],
                [1, '更新候補と経路を選定', 'PHP 8.5.x を候補として記録。移行資料と最終版の確定条件を確認。'],
                [2, 'テスト計画を用意', '機能、性能、互換性、模擬業務フローの手順を記録。'],
                [2, '事前テスト検証', '検証環境の PHP 更新候補と主要フローが合格。'],
                [2, 'リスクを確認', '外部公開、業務影響、復旧難易度から Critical と評価。'],
                [3, '本番バックアップ取得', 'DEMO-BKP-001 に設定、アプリとデータの取得時刻を記録。'],
                [3, '本番バックアップの復元検証', 'DEMO-BKP-001 からの隔離復元とデータ照合に合格。'],
                [4, 'Reviewer の実施条件を確認', '承認記録と復元検証の両方を確認。'],
                [4, 'メンテナンス枠の実施', '手動作業の結果と実績開始時刻を記録。'],
                [5, 'Web・アプリ・DB と業務動作を確認', 'PHP-FPM、ログ、DB 接続、キュー、主要業務フローに異常がない。'],
                [5, '結果と作業記録を確認', '実績終了時刻、最終バージョン、検証結果、作業記録を突合。'],
            ]
            : [
                [1, '対象と影響確認', 'ランタイムと関連サービスの影響範囲を確認。'],
                [2, '事前テスト検証', '模擬環境で PHP と主要業務の事前確認が合格。'],
                [3, '本番バックアップ取得', 'アプリ、構成とデータの取得証跡を確認。'],
                [3, '実施前の復元検証', '隔離環境のバックアップ復元と照合が合格。'],
                [4, '更新作業を記録', '手動作業の結果と適用バージョンを記録。'],
                [5, '作業後の稼働確認', 'Web、アプリ、DB、重要な業務フローの稼働を確認。'],
            ];

        $items = [];
        foreach ($rows as $index => [$phase, $title, $expected]) {
            $sequence = $index + 1;
            $item = $plan->checklistItems()->create([
                'sequence' => $sequence,
                'phase' => ChecklistItem::PHASES[$phase],
                'title' => $title,
                'expected_result' => $expected,
                'status' => 'Pending',
                'is_required' => true,
            ]);
            $this->audit->log($item, 'created', [], $item->only(['update_plan_id', 'sequence', 'phase', 'status']), $operator->id);
            $items[$sequence] = $item;
        }

        return $items;
    }

    private function passPhase(array $items, int $phase, User $operator): void
    {
        foreach ($items as $item) {
            if ($item->phase === ChecklistItem::PHASES[$phase]) {
                $this->check($item, 'Passed', $item->expected_result, $operator);
            }
        }
    }

    private function check(ChecklistItem $item, string $status, string $evidence, User $operator): void
    {
        $old = $item->only(['status', 'evidence']);
        $item->update(['status' => $status, 'evidence' => 'DEMO｜'.$evidence, 'completed_at' => now(), 'completed_by' => $operator->id]);
        $this->audit->log($item, 'checklist_status', $old, $item->only(['status', 'evidence']), $operator->id);
    }

    private function advance(UpdatePlan $plan, string $status, User $operator, array $data = []): UpdatePlan
    {
        return $this->workflow->transition($plan, $status, $data + ['work_record_code' => 'DEMO-WR-'.$plan->plan_code.'-'.$status], $operator);
    }

    private function work(Asset $asset, User $operator, string $key, string $type, string $at, string $summary, string $result, ?UpdatePlan $plan = null, ?Incident $incident = null): void
    {
        $record = WorkRecord::firstOrCreate(['record_code' => 'DEMO-WR-'.$key], [
            'record_type' => $type,
            'asset_id' => $asset->id,
            'user_id' => $operator->id,
            'update_plan_id' => $plan?->id,
            'incident_id' => $incident?->id,
            'summary' => 'DEMO｜'.$summary,
            'action_summary' => $summary,
            'environment' => $asset->environment,
            'result' => $result,
            'evidence_url' => 'https://evidence.demo.invalid/'.$key,
            'started_at' => $at,
            'performed_at' => $at,
        ]);
        if ($record->wasRecentlyCreated) {
            $this->audit->log($record, 'work_record_created', [], $record->only(['record_code', 'update_plan_id', 'incident_id', 'result']), $operator->id);
        }
    }
}
