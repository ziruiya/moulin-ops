@extends('layouts.app')
@section('content')
<h1>ユーザー管理</h1>
@foreach($users as $managedUser)
<form method="post" action="{{ route('settings.users.roles.update', $managedUser) }}">
    @csrf @method('PUT')
    <strong>{{ $managedUser->name }} ({{ $managedUser->email }})</strong>
    @foreach($roleNames as $roleName)
        <label><input type="checkbox" name="roles[]" value="{{ $roleName }}" @checked($managedUser->roles->contains('name', $roleName))>{{ $roleName }}</label>
    @endforeach
    <label>変更承認の付与理由 <input name="grant_reason" maxlength="255"></label>
    <small>Operator と Reviewer を兼任する場合の承認権限に適用します。</small>
    <button>ロールを保存</button>
</form>
@endforeach
{{ $users->links() }}
@endsection
