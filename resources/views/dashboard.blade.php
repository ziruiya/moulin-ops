@extends('layouts.app')

@section('content')
<style>
    .dashboard-grid,.operations-grid{display:grid;gap:1rem}
    .dashboard-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .operations-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
    .dashboard-card{min-width:0;border:1px solid #dce2eb;border-top:3px solid #54718d;border-radius:.5rem;padding:1rem 1.2rem;background:#fff}
    .dashboard-card.urgent{border-top-color:#aa3940}
    .dashboard-card.review{border-top-color:#95630f}
    .dashboard-card h2,.dashboard-card h3{margin:0;line-height:1.4}
    .dashboard-card ul{list-style:none;padding:0;margin:0}
    .dashboard-card li{padding:.65rem 0;border-bottom:1px solid #e6e9f0;overflow-wrap:anywhere}
    .dashboard-card li:last-child{border-bottom:0;padding-bottom:0}
    .dashboard-card small{display:block;color:#435065}
    .dashboard-empty{color:#435065;margin:.6rem 0 0}
    .inventory-steps li{padding:.25rem 0}
    @media(max-width:740px){.dashboard-grid,.operations-grid{grid-template-columns:1fr}}
</style>

<main>
    <h1>運用ダッシュボード</h1>

    @if($initialInventory)
        <section class="dashboard-card" aria-labelledby="inventory-heading">
            <h2 id="inventory-heading">初期棚卸し</h2>
            <ol class="inventory-steps">
                <li><a href="{{ route('assets.index') }}">業務サービスを確認する</a></li>
                <li><a href="{{ route('assets.index') }}">IT資産とコンポーネントを棚卸しする</a></li>
                <li>サービスと資産の依存関係を確認する</li>
                <li><a href="{{ route('work-records.index') }}">バックアップと復元を検証する</a></li>
                <li>ライフサイクルを公式情報で確認する</li>
                <li>責任者とベンダーの連絡先を確認する</li>
                <li><a href="{{ route('update-plans.index') }}">最初のリスク対応計画を作る</a></li>
            </ol>
        </section>
    @else
        <div class="dashboard-grid">
            @foreach([
                'urgent-risks' => ['緊急リスク', $urgentRisks, 'urgent'],
                'data-checks' => ['データ要確認', $dataChecks, 'review'],
                'restore-checks' => ['バックアップと復元', $restoreChecks, 'review'],
                'changes' => ['変更管理', $changes, ''],
            ] as $id => [$heading, $items, $class])
                <section class="dashboard-card {{ $class }}" id="{{ $id }}" aria-labelledby="{{ $id }}-heading">
                    <h2 id="{{ $id }}-heading">{{ $heading }}</h2>
                    @if($items->isEmpty())
                        <p class="dashboard-empty">該当項目はありません。</p>
                    @else
                        <ul>
                            @foreach($items as $item)
                                <li>
                                    <a href="{{ $item['url'] }}">{{ $item['title'] }}</a>
                                    <small>{{ $item['detail'] }}</small>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>

        <section aria-labelledby="operations-heading">
            <h2 id="operations-heading">今日の作業リスト <small>{{ $today->format('Y/m/d') }} JST</small></h2>
            <div class="operations-grid">
                <section class="dashboard-card" id="open-incidents" aria-labelledby="incidents-heading">
                    <h3 id="incidents-heading">未クローズの障害</h3>
                    @if($openIncidents->isEmpty())
                        <p class="dashboard-empty">該当項目はありません。</p>
                    @else
                        <ul>
                            @foreach($openIncidents as $incident)
                                <li><a href="{{ route('incidents.show', $incident) }}">{{ $incident->incident_code }} {{ $incident->title }}</a><small>優先度 {{ $incident->priority }}</small></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
                <section class="dashboard-card" id="pending-vulnerabilities" aria-labelledby="vulnerabilities-heading">
                    <h3 id="vulnerabilities-heading">対応待ちの脆弱性</h3>
                    @if($pendingVulnerabilities->isEmpty())
                        <p class="dashboard-empty">該当項目はありません。</p>
                    @else
                        <ul>
                            @foreach($pendingVulnerabilities as $record)
                                <li><a href="{{ route('vulnerabilities.show', $record) }}">{{ $record->vulnerability_code }} {{ $record->title }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
                <section class="dashboard-card" id="maintenance-windows" aria-labelledby="windows-heading">
                    <h3 id="windows-heading">今日のメンテナンス予定</h3>
                    @if($maintenanceWindows->isEmpty())
                        <p class="dashboard-empty">該当項目はありません。</p>
                    @else
                        <ul>
                            @foreach($maintenanceWindows as $plan)
                                <li>
                                    <a href="{{ route('update-plans.show', $plan) }}">{{ $plan->plan_code }} {{ $plan->title }}</a>
                                    <small>{{ $plan->planned_start_at->copy()->setTimezone($today->timezone)->format('m/d H:i') }}–{{ $plan->planned_end_at->copy()->setTimezone($today->timezone)->format('m/d H:i') }}{{ match ($plan->status) {
                                        'BLOCKED' => '・保留',
                                        'PENDING_APPROVAL' => '・承認待ち',
                                        'APPROVED' => '・承認済み',
                                        'EXECUTING' => '・実施中',
                                        'VERIFYING' => '・確認中',
                                        default => '・実施予定',
                                    } }}</small>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </section>
    @endif
</main>
@endsection
