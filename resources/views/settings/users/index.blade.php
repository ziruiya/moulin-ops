@extends('layouts.app')
@section('content')
<h1>User management</h1>
@foreach($users as $managedUser)
<form method="post" action="{{ route('settings.users.roles.update', $managedUser) }}">
    @csrf @method('PUT')
    <strong>{{ $managedUser->name }} ({{ $managedUser->email }})</strong>
    @foreach($roleNames as $roleName)
        <label><input type="checkbox" name="roles[]" value="{{ $roleName }}" @checked($managedUser->roles->contains('name', $roleName))>{{ $roleName }}</label>
    @endforeach
    <button>Save roles</button>
</form>
@endforeach
{{ $users->links() }}
@endsection
