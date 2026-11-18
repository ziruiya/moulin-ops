@extends('layouts.app')
@section('content')
<main>
    <h1>更新・変更管理 <small>DEMO</small></h1>
    <nav>
        <a href="{{ route('update-plans.index') }}">一覧</a>
        <a href="{{ route('update-plans.index', ['view' => 'status']) }}">状態別</a>
        <a href="{{ route('update-plans.index', ['view' => 'time']) }}">時間表示</a>
        <a href="{{ route('update-plans.create') }}">計画を作成</a>
    </nav>
    @if(request('view') === 'status')
        <h2>状態別</h2>
        @forelse($statusGroups as $status => $group)
            <section>
                <h3>{{ $status }} <small>（{{ $group->count() }}）</small></h3>
                @include('update-plans._plans-table', ['plans' => $group])
            </section>
        @empty
            <p>計画はありません</p>
        @endforelse
    @elseif(request('view') === 'time')
        <h2>時間表示（メンテナンス窓）</h2>
        @forelse($timeGroups as $date => $group)
            <section>
                <h3>{{ $date }} <small>（{{ $group->count() }}）</small></h3>
                @include('update-plans._plans-table', ['plans' => $group])
            </section>
        @empty
            <p>計画はありません</p>
        @endforelse
    @else
        <h2>一覧</h2>
        @include('update-plans._plans-table', ['plans' => $plans])
    @endif
</main>
@endsection
