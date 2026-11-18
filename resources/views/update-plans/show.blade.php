@extends('layouts.app')

@section('content')
@php($labels = \App\Models\UpdatePlan::STATUS_LABELS)
@php($checklistLabels = ['Pending' => '未実施', 'Passed' => '合格', 'Failed' => '不合格', 'Blocked' => '保留', 'Not Applicable' => '対象外', 'Waived' => '免除'])
<main>
    <h1>{{ $plan->plan_code }} — {{ $plan->title }}</h1>
    <p data-status="{{ $plan->status }}">状態 {{ $labels[$plan->status] }} @if($plan->blocked_from_status)（{{ $labels[$plan->blocked_from_status] }}から保留）@endif</p>
    <p>影響 {{ $plan->impact }} / リスク {{ $plan->risk_level }} / {{ $plan->current_version }} → {{ $plan->target_version }}</p>
    @if($plan->reason_detail)<p>{{ $plan->reason_detail }}</p>@endif
    @if($plan->risk_reason)<p>{{ $plan->risk_reason }}</p>@endif
    <p>実施担当 {{ $plan->operator?->name ?? '未定' }} / 承認担当 {{ $plan->reviewer?->name ?? '未定' }}</p>
    <p>資産
        @foreach($plan->assets as $asset)
            <a href="{{ route('assets.show', $asset) }}">{{ $asset->asset_code }}</a>
        @endforeach
    </p>
    <p>対象
        @foreach($plan->components as $component)
            <a href="{{ route('assets.show', $component->asset_id) }}#component-{{ $component->id }}">{{ $component->product_name }} {{ $component->version }}</a>
        @endforeach
    </p>
    @if($plan->patch_source_url)<p><a href="{{ $plan->patch_source_url }}">更新の公式資料</a></p>@endif
    @if($plan->sourceAssessment)
        <p>起点の脆弱性評価 <a href="{{ route('vulnerabilities.show', $plan->sourceAssessment->vulnerability) }}#assessment-{{ $plan->sourceAssessment->id }}">{{ $plan->sourceAssessment->vulnerability->vulnerability_code }}</a></p>
    @endif
    <p>バックアップ {{ $plan->backup_verified ? '完了' : '未完了' }} / 復元検証 {{ $plan->restore_test_passed ? '確認済み' : '未確認' }} @if($plan->backup_reference) / 証跡 {{ $plan->backup_reference }} @endif</p>
    <p>実績開始 {{ $plan->actual_start_at }} / 実績終了 {{ $plan->actual_end_at }}（{{ config('app.timezone') }}）</p>
    @if($plan->completion_result)<p>結果 {{ $plan->completion_result }}</p>@endif

    @can('update', $plan)
        <details>
            <summary>計画・証跡の編集</summary>
            <form method="post" action="{{ route('update-plans.update', $plan) }}">
                @csrf @method('PUT')
                @foreach(['impact' => '影響調査', 'test_plan' => 'テスト計画', 'implementation_plan' => '実施計画', 'verification_plan' => '検証計画', 'rollback_plan' => 'ロールバック計画', 'rollback_trigger' => 'ロールバック条件'] as $field => $label)
                    <p><label>{{ $label }} <textarea name="{{ $field }}">{{ old($field, $plan->$field) }}</textarea></label></p>
                @endforeach
                <label>バックアップ証跡 <input name="backup_reference" value="{{ $plan->backup_reference }}"></label>
                <input type="hidden" name="backup_verified" value="0">
                <label><input type="checkbox" name="backup_verified" value="1" @checked($plan->backup_verified)>バックアップ検証</label>
                <input type="hidden" name="restore_test_passed" value="0">
                <label><input type="checkbox" name="restore_test_passed" value="1" @checked($plan->restore_test_passed)>復元確認</label>
                <label>予定開始（{{ config('app.timezone') }}） <input type="datetime-local" name="planned_start_at" value="{{ $plan->planned_start_at?->format('Y-m-d\TH:i') }}"></label>
                <label>予定終了（{{ config('app.timezone') }}） <input type="datetime-local" name="planned_end_at" value="{{ $plan->planned_end_at?->format('Y-m-d\TH:i') }}"></label>
                <label>実施担当
                    <select name="operator_id">
                        <option value="">未定</option>
                        @foreach($operators as $operator)<option value="{{ $operator->id }}" @selected($operator->id === $plan->operator_id)>{{ $operator->name }}</option>@endforeach
                    </select>
                </label>
                <button>保存</button>
            </form>
        </details>
    @endcan

    @can('transition', $plan)
        <h2>状態操作</h2>
        <form method="post" action="{{ route('update-plans.transition', $plan) }}">
            @csrf
            <p><label>変更先
                <select name="status">
                    @foreach($labels as $state => $label)@if($state !== 'DRAFT')<option value="{{ $state }}" @selected(old('status') === $state)>{{ $label }}</option>@endif @endforeach
                </select>
            </label></p>
            <p><label>検証・事後レビュー結果 <textarea name="completion_result">{{ old('completion_result') }}</textarea></label></p>
            <p><label>実施作業の記録 <textarea name="action_summary">{{ old('action_summary') }}</textarea></label></p>
            <p><label>ロールバック理由 <textarea name="rollback_reason">{{ old('rollback_reason') }}</textarea></label></p>
            <p><label>復元バージョンと検証の結果 <textarea name="restore_verification_result">{{ old('restore_verification_result') }}</textarea></label></p>
            <p><label>関連障害のタイトル <input name="incident[title]" value="{{ old('incident.title') }}"></label></p>
            <p><label>関連障害の内容 <textarea name="incident[description]">{{ old('incident.description') }}</textarea></label></p>
            <button>遷移を記録</button>
        </form>
    @endcan
    @foreach($plan->incidents as $incident)
        <p>関連障害 <a href="{{ route('incidents.show', $incident) }}">{{ $incident->incident_code }} {{ $incident->title }}</a></p>
    @endforeach
    <p><a href="{{ route('work-records.index', ['update_plan_id' => $plan->id]) }}">この計画の作業記録</a></p>

    <h2>チェックリスト</h2>
    @foreach($plan->checklistItems->sortBy([['phase', 'asc'], ['sequence', 'asc']]) as $item)
        <p data-checklist-status="{{ $item->status }}">{{ $item->sequence }}. {{ $item->title }} — {{ $checklistLabels[$item->status] }} @if($item->evidence) / {{ $item->evidence }} @endif</p>
        @if(!$item->correction_of_id && $item->passedCorrections->isEmpty() && auth()->user()->can('update', $plan))
            <form method="post" action="{{ route('checklist-items.status', $item) }}">
                @csrf
                @if($item->status === 'Failed')
                    <input type="hidden" name="status" value="Passed">
                    <label>是正理由 <input name="correction_reason" required></label>
                    <label>再検証の証跡 <input name="evidence" required></label>
                @else
                    <label>結果 <select name="status">@foreach($checklistLabels as $status => $label)@if($status !== 'Waived')<option value="{{ $status }}" @selected($status === $item->status)>{{ $label }}</option>@endif @endforeach</select></label>
                    <label>証跡 <input name="evidence" value="{{ $item->evidence }}"></label>
                @endif
                <button>更新</button>
            </form>
        @endif
        @if(!in_array($item->status, ['Failed', 'Passed'], true) && auth()->user()->can('approve', $plan))
            <details>
                <summary>チェック項目の免除</summary>
                <form method="post" action="{{ route('checklist-items.status', $item) }}">
                    @csrf
                    <input type="hidden" name="status" value="Waived">
                    <label>免除理由 <input name="exception_reason" required></label>
                    <label>適用範囲 <select name="waiver_scope">@foreach(\App\Models\ChecklistItem::WAIVER_SCOPES as $scope => $label)<option value="{{ $scope }}">{{ $label }}</option>@endforeach</select></label>
                    <button>免除を記録</button>
                </form>
            </details>
        @endif
    @endforeach
    @can('update', $plan)
        <form method="post" action="{{ route('update-plans.checklist', $plan) }}">
            @csrf
            <label>項目 <input name="title" required></label>
            <label>段階
                <select name="phase">
                    @foreach(['影響調査', '事前検証', 'バックアップと復元準備', '実施', '実施後の確認'] as $index => $label)
                        <option value="{{ \App\Models\ChecklistItem::PHASES[$index + 1] }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <input type="hidden" name="is_required" value="0">
            <label><input type="checkbox" name="is_required" value="1" checked>必須</label>
            <button>項目追加</button>
        </form>
    @endcan

    <h2>承認記録</h2>
    @foreach($plan->approvals as $approval)
        <p>{{ $approval->decision === 'APPROVED' ? '承認' : '差し戻し' }} / {{ $approval->decided_at }} / {{ $approval->approver->name }} @if($approval->comments) / {{ $approval->comments }} @endif</p>
    @endforeach
    @can('approve', $plan)
        @if($plan->status === 'PENDING_APPROVAL')
            <form method="post" action="{{ route('update-plans.approve', $plan) }}">
                @csrf
                <label>判断 <select name="decision"><option value="APPROVED">承認</option><option value="REJECTED">差し戻し</option></select></label>
                <label>コメント <textarea name="comments"></textarea></label>
                <button>判断を記録</button>
            </form>
        @endif
    @endcan
    <button type="button" onclick="document.getElementById('aiDemo').showModal()">AI調査テンプレート（DEMO）</button>
    <dialog id="aiDemo">
        <h2>AI調査テンプレート（DEMO）</h2>
        <b>DEMO — AI連携なし</b>
        <h3>調査質問の例</h3>
        <p>対象製品とバージョン、公式情報源、影響条件、修正バージョン、互換性上の注意を確認する。推測を明示し、変更操作を実行しない。</p>
        <h3>出力形式</h3>
        <p>確認済みの事実、未確認事項、確認に使った資料、次の検証作業を記載します。</p>
        <p>担当者が根拠を手動で確認し、必要内容を転記します。人手確認が必須です。</p>
        <label><input type="checkbox">人が内容を確認しました（デモ確認欄）</label>
        <button type="button" onclick="document.getElementById('aiDemo').close()">閉じる</button>
    </dialog>
</main>
@endsection
