<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\Incident;
use App\Models\UpdatePlan;
use App\Models\VulnerabilityComponentAssessment;
use App\Models\VulnerabilityRecord;
use App\Services\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const TIMEZONE = 'Asia/Tokyo';

    private const OPEN_HANDLING = ['OPEN', 'ASSESSING', 'ACTION_REQUIRED', 'REMEDIATION_PLANNED', 'IN_REMEDIATION', 'FIX_PENDING_VERIFICATION'];

    private const CHANGE_STAGES = [
        'PENDING_APPROVAL' => ['承認待ち', 0],
        'APPROVED' => ['実施待ち', 1],
        'SCHEDULED' => ['実施予定', 1],
        'VERIFYING' => ['実施後の確認待ち', 2],
        'EMERGENCY_REVIEW' => ['事後レビュー待ち', 2],
    ];

    private const WINDOW_STATUSES = ['PENDING_APPROVAL', 'APPROVED', 'SCHEDULED', 'EXECUTING', 'VERIFYING', 'BLOCKED'];

    public function __invoke(SettingsService $settings): View
    {
        $this->authorize('viewAny', Asset::class);
        $now = now(self::TIMEZONE);
        $today = $now->copy()->startOfDay();
        $rules = $settings->rules();
        $reviewCutoff = $now->copy()->subDays($rules['data_review_days']);
        $restoreCutoff = $today->copy()->subDays($rules['restore_review_days']);
        $assets = Asset::with(['components.currentLifecycle', 'latestRestoreTest'])
            ->where('operational_status', '!=', 'Retired')->whereNull('deleted_at')->get();
        $vulnerabilities = VulnerabilityRecord::with(['assessments' => fn ($query) => $query
            ->whereDoesntHave('reassessments')->with('component.asset')])->get();
        $plans = UpdatePlan::query()->get();
        $openIncidents = Incident::whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->orderBy('priority')->orderByRaw('detected_at IS NULL')->orderBy('detected_at')->orderBy('incident_code')->get();

        return view('dashboard', [
            'initialInventory' => $assets->isEmpty() && $vulnerabilities->isEmpty() && $plans->isEmpty() && $openIncidents->isEmpty(),
            'today' => $today,
            'urgentRisks' => $this->urgentRisks($assets, $vulnerabilities, $today, $reviewCutoff, $rules['eol_warning_days']),
            'dataChecks' => $this->dataChecks($assets, $reviewCutoff),
            'restoreChecks' => $this->restoreChecks($assets, $restoreCutoff),
            'changes' => $this->changes($plans),
            'openIncidents' => $openIncidents,
            'pendingVulnerabilities' => $this->pendingVulnerabilities($vulnerabilities),
            'maintenanceWindows' => $this->maintenanceWindows($plans, $today),
        ]);
    }

    private function urgentRisks(Collection $assets, Collection $vulnerabilities, CarbonInterface $today, CarbonInterface $reviewCutoff, int $warningDays): Collection
    {
        $risks = collect();
        foreach ($vulnerabilities as $vulnerability) {
            if (! in_array($vulnerability->severity, ['CRITICAL', 'HIGH'], true)) {
                continue;
            }
            foreach ($this->openAssessments($vulnerability) as $assessment) {
                $component = $assessment->component;
                $asset = $component->asset;
                if ($assessment->match_status !== 'CONFIRMED_AFFECTED'
                    || $assessment->installed_version_snapshot !== $component->version
                    || $asset->operational_status === 'Retired' || $asset->deleted_at) {
                    continue;
                }
                $exposed = $asset->internet_exposed || (bool) $vulnerability->production_exposed;
                $exploited = $vulnerability->exploited_status === 'CONFIRMED';
                $risks->push([
                    'url' => route('vulnerabilities.show', $vulnerability).'#assessment-'.$assessment->id,
                    'title' => $vulnerability->vulnerability_code.' '.$vulnerability->title,
                    'detail' => $asset->asset_code.' / '.$assessment->component->product_name.' / 影響あり（'.($vulnerability->severity === 'CRITICAL' ? '重大' : '高').($exploited ? '・悪用確認' : '').'）',
                    'order' => [$exploited ? 0 : 1, $exposed ? 0 : 1, $this->criticality($asset), 0, $vulnerability->severity === 'CRITICAL' ? 0 : 1, $vulnerability->vulnerability_code, $assessment->id],
                ]);
            }
        }
        foreach ($assets as $asset) {
            foreach ($asset->components as $component) {
                $production = $component->environment ? strcasecmp($component->environment, 'Production') === 0 : $asset->isProduction();
                $lifecycle = $component->verifiedLifecycle();
                if (! $production || ! $lifecycle || $lifecycle->verified_at->lt($reviewCutoff)) {
                    continue;
                }
                $ended = $lifecycle->hasEndedSupport($today);
                if (! $ended && ! $lifecycle->endsSupportSoon($today, $warningDays)) {
                    continue;
                }
                $label = $ended ? '本番・サポート終了（EOL）' : '本番・サポート終了間近（EOL）';
                $risks->push([
                    'url' => $this->componentUrl($asset, $component),
                    'title' => $asset->asset_code.' '.$component->product_name.' '.$component->version,
                    'detail' => $label.($lifecycle->eol_date ? ' / '.$lifecycle->eol_date : '').' / '.$lifecycle->source_name,
                    'order' => [$ended ? 1 : 2, $asset->internet_exposed ? 0 : 1, $this->criticality($asset), 1, 0, $asset->asset_code, $component->id],
                ]);
            }
        }

        return $risks->sortBy('order')->values();
    }

    private function dataChecks(Collection $assets, CarbonInterface $cutoff): Collection
    {
        $checks = collect();
        foreach ($assets as $asset) {
            $issues = [];
            if (blank($asset->inventory_source)) {
                $issues[] = '棚卸しの情報源が不明';
            }
            if (! $asset->last_verified_at || $asset->inventory_status !== 'Verified') {
                $issues[] = match ($asset->inventory_status) {
                    'Conflict' => '棚卸しの情報源が不一致',
                    'Stale' => '棚卸し情報が更新期限超過',
                    default => '棚卸し未確認',
                };
            } elseif ($asset->last_verified_at->lt($cutoff)) {
                $issues[] = '棚卸し情報が更新期限超過';
            }
            if ($issues !== []) {
                $checks->push([
                    'url' => route('assets.show', $asset),
                    'title' => $asset->asset_code.' '.$asset->name,
                    'detail' => implode(' / ', $issues),
                    'order' => [$this->criticality($asset), $asset->asset_code, 0],
                ]);
            }
            foreach ($asset->components as $component) {
                $issues = [];
                if (blank($component->version_source)) {
                    $issues[] = 'バージョンの情報源が不明';
                }
                $lifecycle = $component->verifiedLifecycle();
                if (! $lifecycle) {
                    $issues[] = '現在のバージョンのライフサイクルが未確認';
                } elseif ($lifecycle->lifecycle_status === 'CONFLICT') {
                    $issues[] = 'ライフサイクルの情報源が不一致';
                } elseif ($lifecycle->lifecycle_status === 'STALE' || $lifecycle->verified_at->lt($cutoff)) {
                    $issues[] = 'ライフサイクル情報が更新期限超過';
                } elseif ($lifecycle->support_phase === 'Unknown' || blank($lifecycle->eol_date)) {
                    $issues[] = 'サポート終了日不明';
                }
                if ($issues !== []) {
                    $checks->push([
                        'url' => $this->componentUrl($asset, $component),
                        'title' => $asset->asset_code.' '.$component->product_name.' '.($component->version ?? 'バージョン不明'),
                        'detail' => implode(' / ', $issues),
                        'order' => [$this->criticality($asset), $asset->asset_code, $component->id],
                    ]);
                }
            }
        }

        return $checks->sortBy('order')->values();
    }

    private function restoreChecks(Collection $assets, CarbonInterface $cutoff): Collection
    {
        $checks = collect();
        foreach ($assets as $asset) {
            $test = $asset->latestRestoreTest;
            if (! $test && (! $asset->isProduction() || $asset->asset_type === 'Service')) {
                continue;
            }
            $performed = $test ? Carbon::parse($test->performed_at)->setTimezone(self::TIMEZONE) : null;
            $finding = match (true) {
                $test?->result === 'Failed' => '復元テスト失敗',
                $test?->result === 'Partial' => '復元テスト未完了',
                ! $test || $test->result === 'Pending' => '復元未検証',
                $performed?->lt($cutoff) => '復元テストの再検証期限超過',
                default => null,
            };
            if ($finding !== null) {
                $checks->push([
                    'url' => $test ? route('work-records.show', $test) : route('assets.show', $asset),
                    'title' => $asset->asset_code.' '.$asset->name,
                    'detail' => $finding.($performed ? ' / 最終記録 '.$performed->toDateString() : ''),
                    'order' => [$test?->result === 'Failed' ? 0 : 1, $this->criticality($asset), $performed?->timestamp ?? 0, $asset->asset_code],
                ]);
            }
        }

        return $checks->sortBy('order')->values();
    }

    private function changes(Collection $plans): Collection
    {
        $changes = collect();
        foreach ($plans as $plan) {
            $stage = $plan->status === 'BLOCKED' ? $plan->blocked_from_status : $plan->status;
            $stageInfo = self::CHANGE_STAGES[$stage] ?? null;
            if (! $stageInfo && $plan->status !== 'BLOCKED') {
                continue;
            }
            [$label, $order] = $stageInfo ?? ['進行待ち', 3];
            if ($plan->status === 'BLOCKED') {
                $label .= '・保留';
                if ($stage === 'SCHEDULED' && ! $plan->restore_test_passed) {
                    $label .= '（復元未検証）';
                }
            }
            $changes->push([
                'url' => route('update-plans.show', $plan),
                'title' => $plan->plan_code.' '.$plan->title,
                'detail' => $label,
                'order' => [$order, $this->severityOrder($plan->risk_level), $plan->planned_start_at?->timestamp ?? PHP_INT_MAX, $plan->plan_code],
            ]);
        }

        return $changes->sortBy('order')->values();
    }

    private function pendingVulnerabilities(Collection $vulnerabilities): Collection
    {
        return $vulnerabilities
            ->filter(fn (VulnerabilityRecord $record) => $record->assessments->isEmpty()
                ? in_array($record->handling_status, self::OPEN_HANDLING, true)
                : $this->openAssessments($record)->isNotEmpty())
            ->sortBy(fn (VulnerabilityRecord $record) => [
                $record->exploited_status === 'CONFIRMED' ? 0 : 1,
                $record->production_exposed ? 0 : 1,
                $this->severityOrder($record->severity),
                $record->vulnerability_code,
            ])->values();
    }

    private function openAssessments(VulnerabilityRecord $vulnerability): Collection
    {
        return $vulnerability->assessments->filter(fn (VulnerabilityComponentAssessment $assessment) => in_array($assessment->handling_status, self::OPEN_HANDLING, true)
            && (! in_array($assessment->match_status, ['CONFIRMED_NOT_AFFECTED', 'NOT_APPLICABLE'], true)
                || $assessment->installed_version_snapshot !== $assessment->component->version));
    }

    private function maintenanceWindows(Collection $plans, CarbonInterface $today): Collection
    {
        $tomorrow = $today->copy()->addDay();

        return $plans->filter(fn (UpdatePlan $plan) => in_array($plan->status, self::WINDOW_STATUSES, true)
            && $plan->planned_start_at && $plan->planned_end_at
            && $plan->planned_end_at->gt($plan->planned_start_at)
            && $plan->planned_start_at->lt($tomorrow) && $plan->planned_end_at->gt($today))
            ->sortBy(fn (UpdatePlan $plan) => [$plan->planned_start_at->timestamp, $plan->plan_code])->values();
    }

    private function componentUrl(Asset $asset, AssetComponent $component): string
    {
        return route('assets.show', $asset).'#component-'.$component->id;
    }

    private function criticality(Asset $asset): int
    {
        return $this->severityOrder($asset->criticality);
    }

    private function severityOrder(?string $severity): int
    {
        return match (strtoupper($severity ?? '')) {
            'CRITICAL' => 0,
            'HIGH' => 1,
            'MEDIUM' => 2,
            'LOW' => 3,
            default => 4,
        };
    }
}
