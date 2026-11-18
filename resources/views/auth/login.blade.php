<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MoulinOps ログイン</title>
</head>
<body>
<main>
    <h1>MoulinOps ログイン</h1>
    <p>DEMO DATA｜想定環境｜実在企業の構成ではありません</p>
    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <p><label>ユーザー名またはメールアドレス <input name="login" value="{{ old('login') }}" autocomplete="username" required></label></p>
        <p><label>パスワード <input type="password" name="password" autocomplete="current-password" required></label></p>
        <button>ログイン</button>
    </form>
    @if($errors->any())
        <p role="alert">ユーザー名またはパスワードを確認してください。</p>
    @endif
</main>
</body>
</html>
