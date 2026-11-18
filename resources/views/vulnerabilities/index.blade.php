@extends('layouts.app')
@section('content')
<h1>脆弱性管理 <small>DEMO</small></h1>
<p><a href="{{ route('vulnerabilities.create') }}">脆弱性を登録</a></p>
<table><thead><tr><th>優先</th><th>コード / 脆弱性</th><th>深刻度・CVSS</th><th>利用・影響</th><th>判定理由</th><th>対応</th></tr></thead><tbody>
@forelse($records as $record)<tr><td>{{ $record->priority_data['priority'] }}</td><td><a href="{{ route('vulnerabilities.show',$record) }}">{{ $record->vulnerability_code }} / {{ $record->title }}</a></td><td>{{ $record->severity ?? '未評価' }} / {{ $record->cvss_score ?? '—' }}</td><td>{{ ['CONFIRMED'=>'悪用確認','NOT_KNOWN'=>'既知の悪用情報なし','UNKNOWN'=>'不明'][$record->exploited_status] }}・{{ $record->production_exposed ? '本番公開' : '本番公開なし' }}・{{ $record->business_criticality ?? '重要度不明' }}</td><td>{{ $record->priority_reason ?: $record->priority_data['reason'] }}</td><td>{{ $record->assessments->count() }} 件</td></tr>@empty<tr><td colspan="6">脆弱性はまだ登録されていません。</td></tr>@endforelse
</tbody></table>
<p>優先度は悪用確認、本番インターネット公開、業務重要度、修正版、緩和策で算出。CVSSは補助情報です。</p>
@endsection
