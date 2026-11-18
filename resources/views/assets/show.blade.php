@extends('layouts.app')

@section('content')
@php($supportPhases = ['Unknown' => '不明', 'Active' => '通常サポート', 'Security Only' => 'セキュリティ修正のみ', 'Extended' => '延長サポート', 'EOL' => 'サポート終了'])
<main>
    <h1>{{ $asset->name }}（{{ $asset->asset_code }}）</h1>
    <p>{{ $asset->asset_type }} / {{ $asset->environment }} / {{ $asset->operational_status }} / {{ $asset->criticality }}</p>
    @if($asset->description)<p>{{ $asset->description }}</p>@endif
    <p>棚卸し情報源 {{ $asset->inventory_source ?? '不明' }} / 最終確認 {{ $asset->last_verified_at?->toDateString() ?? '未確認' }}</p>
    @can('update', $asset)
        <a href="{{ route('assets.edit', $asset) }}">資産を編集</a>
    @endcan

    <h2>ベンダーと外部サポート</h2>
    <dl>
        <dt>ベンダー</dt><dd>{{ $asset->vendor ?? '未登録' }}</dd>
        <dt>外部サポート連絡先</dt><dd>{{ $asset->support_contact ?? '未登録' }}</dd>
        <dt>外部チケット参照</dt><dd>{{ $asset->vendor_ticket_ref ?? '未登録' }}</dd>
    </dl>

    <h2>コンポーネントとライフサイクル</h2>
    @forelse($asset->components as $component)
        <section id="component-{{ $component->id }}">
            <h3>{{ $component->product_name }} {{ $component->version ?? 'バージョン不明' }}</h3>
            <p>{{ $component->component_type }} / {{ $component->environment ?? $asset->environment }} / {{ $component->vendor_name ?? '提供元不明' }}</p>
            <p>バージョンの情報源 {{ $component->version_source ?? '不明' }} / ライフサイクル {{ $component->lifecycleLabel($lifecycleRules) }}</p>
            @if($component->currentLifecycle)
                @php($lifecycle = $component->currentLifecycle)
                <p>
                    最新の確認 <a href="{{ $lifecycle->source_url }}">{{ $lifecycle->source_name }}</a>
                    / {{ $lifecycle->verified_at->toDateString() }}
                    / 対象 {{ $lifecycle->product_name }} {{ $lifecycle->version ?? 'バージョン不明' }}
                    @if($lifecycle->data_source_type === 'OFFICIAL_MANUAL_SEED') / 公式情報の手動登録（DEMO）@endif
                    @if($lifecycle->data_source_type === 'OFFICIAL_MANUAL') / 手動確認@endif
                </p>
                <p>通常サポート終了 {{ $lifecycle->active_support_end ?? '日付不明' }} / セキュリティ対応終了 {{ $lifecycle->security_support_end ?? '日付不明' }} / サポート終了 {{ $lifecycle->eol_date ?? '日付不明' }}</p>
                <details>
                    <summary>確認履歴</summary>
                    <ul>
                        @foreach($component->lifecycleRecords->sortByDesc('verified_at') as $record)
                            <li>{{ $record->verified_at }} / {{ $record->version ?? 'バージョン不明' }} / {{ $supportPhases[$record->support_phase] }} / <a href="{{ $record->source_url }}">{{ $record->source_name }}</a></li>
                        @endforeach
                    </ul>
                </details>
            @endif
            @can('update', $asset)
                <details>
                    <summary>コンポーネントを編集</summary>
                    <form method="post" action="{{ route('assets.components.update', [$asset, $component]) }}">
                        @csrf @method('PUT')
                        <label>種類 <input name="component_type" value="{{ $component->component_type }}" required></label>
                        <label>提供元 <input name="vendor_name" value="{{ $component->vendor_name }}"></label>
                        <label>製品 <input name="product_name" value="{{ $component->product_name }}" required></label>
                        <label>実稼働バージョン <input name="version" value="{{ $component->version }}"></label>
                        <label>バージョンの情報源 <input name="version_source" value="{{ $component->version_source }}"></label>
                        <label>環境 <input name="environment" value="{{ $component->environment }}"></label>
                        <label>サポート段階
                            <select name="support_phase">
                                @foreach($supportPhases as $phase => $label)<option value="{{ $phase }}" @selected($component->support_phase === $phase)>{{ $label }}</option>@endforeach
                            </select>
                        </label>
                        <button>保存</button>
                    </form>
                </details>
            @endcan
        </section>
    @empty
        <p>コンポーネントは未登録です。</p>
    @endforelse

    @can('update', $asset)
        <details>
            <summary>コンポーネントを追加</summary>
            <form method="post" action="{{ route('assets.components.store', $asset) }}">
                @csrf
                <label>種類 <input name="component_type" required></label>
                <label>提供元 <input name="vendor_name"></label>
                <label>製品 <input name="product_name" required></label>
                <label>実稼働バージョン <input name="version"></label>
                <label>バージョンの情報源 <input name="version_source"></label>
                <label>環境 <input name="environment"></label>
                <label>サポート段階
                    <select name="support_phase">
                        @foreach($supportPhases as $phase => $label)<option value="{{ $phase }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
                <button>追加</button>
            </form>
        </details>
        @if($asset->components->isNotEmpty())
            <h2>ライフサイクルを確認</h2>
            <form method="post" action="{{ route('assets.lifecycle.store', $asset) }}">
                @csrf
                <label>コンポーネント
                    <select name="component_id">@foreach($asset->components as $component)<option value="{{ $component->id }}">{{ $component->product_name }} {{ $component->version }}</option>@endforeach</select>
                </label>
                <label>情報源URL <input type="url" name="source_url" required></label>
                <label>情報源名 <input name="source_name" required></label>
                <label>確認日時（{{ config('app.timezone') }}） <input type="datetime-local" name="verified_at" required></label>
                <label>サポート段階
                    <select name="support_phase">@foreach($supportPhases as $phase => $label)<option value="{{ $phase }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label>日付の精度
                    <select name="date_precision">
                        <option value="DAY">日</option><option value="MONTH">月</option><option value="QUARTER">四半期</option><option value="UNKNOWN">不明</option>
                    </select>
                </label>
                @foreach(['active_support_end' => '通常サポート終了', 'security_support_end' => 'セキュリティ対応終了', 'eol_date' => 'サポート終了', 'extended_support_end' => '延長サポート終了'] as $field => $label)
                    <label>{{ $label }} <input type="date" name="{{ $field }}"></label>
                @endforeach
                <button>確認を記録</button>
            </form>
        @endif
    @endcan

    <h2>資産とサービスの依存関係</h2>
    @foreach($asset->relationships as $relationship)
        <div>
            {{ $relationship->relationship_type }} → <a href="{{ route('assets.show', $relationship->targetAsset) }}">{{ $relationship->targetAsset->asset_code }} {{ $relationship->targetAsset->name }}</a>
            @can('update', $asset)
                <form method="post" action="{{ route('assets.relationships.destroy', [$asset, $relationship]) }}">@csrf @method('DELETE')<button>関連を削除</button></form>
            @endcan
        </div>
    @endforeach
    @foreach($asset->incomingRelationships as $relationship)
        <p>{{ $relationship->relationship_type }} ← <a href="{{ route('assets.show', $relationship->sourceAsset) }}">{{ $relationship->sourceAsset->asset_code }} {{ $relationship->sourceAsset->name }}</a></p>
    @endforeach
    @can('update', $asset)
        <form method="post" action="{{ route('assets.relationships.store', $asset) }}">
            @csrf
            <label>資産 <select name="target_asset_id">@foreach($targetAssets as $target)<option value="{{ $target->id }}">{{ $target->asset_code }} ? {{ $target->name }}</option>@endforeach</select></label>
            <label>関連の種類 <select name="relationship_type"><option>Depends on</option><option>Runs on</option><option>Hosted on</option><option>Connects to</option></select></label>
            <button>関連を追加</button>
        </form>
    @endcan
</main>
@endsection
