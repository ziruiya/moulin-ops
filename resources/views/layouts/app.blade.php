<!doctype html>
<html lang="ja">
<meta charset="utf-8">
<title>MoulinOps</title>
<style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#182334}nav{display:flex;gap:1rem;border-bottom:1px solid #ccc;padding:1rem 0}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ddd;padding:.6rem;text-align:left}input,select,textarea{padding:.5rem;margin:.3rem}button{padding:.5rem 1rem}.demo{background:#fff4d6;padding:.6rem}</style>
<body>
<nav>
<a href="{{ route('assets.index') }}">IT??</a>
@if(auth()->user()->roles()->where('name', 'Admin')->exists())<a href="{{ route('settings.users.index') }}">??????</a>@endif
<form method="post" action="{{ route('logout') }}">@csrf<button>?????</button></form>
</nav>
<p class="demo">DEMO DATA????????????????????</p>
@if(session('status'))<p>{{ session('status') }}</p>@endif
@yield('content')
</body>
</html>
