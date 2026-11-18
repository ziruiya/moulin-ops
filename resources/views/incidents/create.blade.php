@extends('layouts.app')
@section('content')
<h1>障害を登録</h1><p class="demo">DEMO DATA｜想定環境｜実在企業の構成ではありません</p>@include('incidents._form',['action'=>route('incidents.store'),'method'=>'POST'])
@endsection
