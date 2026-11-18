@extends('layouts.app')

@section('content')
<main>
    <h1>システム設定</h1>

    <section aria-labelledby="lifecycle-rules">
        <h2 id="lifecycle-rules">データソースとライフサイクルのルール</h2>
        <form method="post" action="{{ route('settings.system.update') }}">
            @csrf
            @foreach($ruleLabels as $field => $label)
                <p>
                    <label for="{{ $field }}">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="number" value="{{ $ruleValues[$field] }}"
                           min="{{ $minDays }}" max="{{ $maxDays }}" step="1" required>
                    日
                </p>
            @endforeach
            <button type="submit">ルールを保存</button>
        </form>

        <h3>登録済みのデータソース</h3>
        <h4>データソース種別</h4>
        @if($sourceTypes->isEmpty())
            <p>登録はありません。</p>
        @else
            <ul>
                @foreach($sourceTypes as $type)
                    <li>{{ $type }}</li>
                @endforeach
            </ul>
        @endif

        <h4>公式情報の URL</h4>
        @if($sourceUrls->isEmpty())
            <p>登録はありません。</p>
        @else
            <ul style="overflow-wrap: anywhere">
                @foreach($sourceUrls as $url)
                    <li><a href="{{ $url }}">{{ $url }}</a></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="system-config">
        <h2 id="system-config">システム構成</h2>
        <table>
            <tbody>
                @foreach($systemConfig as $label => $value)
                    <tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
</main>
@endsection
