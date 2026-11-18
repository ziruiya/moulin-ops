@extends('layouts.app')

@section('content')
<main>
    <h1>IT資産一覧</h1>
    <form>
        <input name="q" aria-label="コードまたは名称" placeholder="コード・名称" value="{{ request('q') }}">
        <select name="asset_type" aria-label="資産の種類">
            <option value="">すべての種類</option>
            @foreach(['Service' => '業務サービス', 'Server' => 'サーバー', 'Application' => 'アプリケーション', 'Database' => 'データベース'] as $type => $label)
                <option value="{{ $type }}" @selected(request('asset_type') === $type)>{{ $label }}</option>
            @endforeach
        </select>
        <input name="environment" aria-label="環境" placeholder="環境" value="{{ request('environment') }}">
        <select name="operational_status" aria-label="稼働状態">
            <option value="">すべての稼働状態</option>
            @foreach(['Active' => '稼働中', 'Degraded' => '一部停止', 'Maintenance' => 'メンテナンス中', 'Retired' => '廃止済み'] as $status => $label)
                <option value="{{ $status }}" @selected(request('operational_status') === $status)>{{ $label }}</option>
            @endforeach
        </select>
        <input name="criticality" aria-label="重要度" placeholder="重要度" value="{{ request('criticality') }}">
        <button>検索</button>
    </form>
    @can('create', \App\Models\Asset::class)
        <p><a href="{{ route('assets.create') }}">資産を登録</a></p>
    @endcan
    <table>
        <thead><tr><th>コード</th><th>名称</th><th>種類</th><th>環境</th><th>稼働状態</th><th>ライフサイクル</th></tr></thead>
        <tbody>
            @forelse($assets as $asset)
                <tr>
                    <td><a href="{{ route('assets.show', $asset) }}">{{ $asset->asset_code }}</a></td>
                    <td>{{ $asset->name }}</td><td>{{ $asset->asset_type }}</td><td>{{ $asset->environment }}</td><td>{{ $asset->operational_status }}</td>
                    <td>{{ $asset->components->map->lifecycleLabel($lifecycleRules)->unique()->join(' / ') ?: 'コンポーネント未登録' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">該当する資産はありません。</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $assets->withQueryString()->links() }}
</main>
@endsection
