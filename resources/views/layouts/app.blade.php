<!doctype html>
<html lang="ja">
<meta charset="utf-8">
<title>MoulinOps</title>
<style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#182334}nav{display:flex;gap:1rem;border-bottom:1px solid #ccc;padding:1rem 0}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ddd;padding:.6rem;text-align:left}input,select,textarea{padding:.5rem;margin:.3rem}button{padding:.5rem 1rem}.demo{background:#fff4d6;padding:.6rem}</style>
<body>
<nav>
<a href="{{ route('incidents.index') }}">障害管理</a>
<a href="{{ route('work-records.index') }}">運用記録</a>
<a href="{{ route('audit-events.index') }}">監査記録</a>
<a href="{{ route('assets.index') }}">IT資産</a>
<a href="{{ route('vulnerabilities.index') }}">脆弱性管理</a>
<a href="{{ route('update-plans.index') }}">更新・変更管理</a>
@if(auth()->user()->roles()->where('name', 'Admin')->exists())<a href="{{ route('settings.users.index') }}">ユーザー管理</a>@endif
<form method="post" action="{{ route('logout') }}">@csrf<button>ログアウト</button></form>
</nav>
<p class="demo">DEMO DATA — 想定環境の架空データです。実在企業の運用状態を示すものではありません。</p>
@if(session('status'))<p>{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert"><p>入力内容を確認してください。</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</body>
</html>
