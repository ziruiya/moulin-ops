@extends('layouts.app')
@section('content')<h1>脆弱性を登録 <small>DEMO</small></h1>
@if($errors->any())<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form method="post" action="{{ route('vulnerabilities.store') }}">@csrf
@foreach(['vulnerability_code'=>'脆弱性コード','title'=>'名称','source_name'=>'情報源','source_url'=>'情報源URL','cvss_score'=>'CVSS','remediation_version'=>'修正版','priority_reason'=>'優先度の判断理由'] as $key=>$label)<label>{{ $label }} <input name="{{ $key }}" value="{{ old($key) }}" @if(in_array($key,['vulnerability_code','title','priority_reason'])) required @endif></label><br>@endforeach
<label>説明 <textarea name="description">{{ old('description') }}</textarea></label><br>
<label>深刻度 <select name="severity"><option value="">未評価</option>@foreach(['CRITICAL','HIGH','MEDIUM','LOW'] as $v)<option>{{ $v }}</option>@endforeach</select></label>
<label>悪用状況 <select name="exploited_status"><option>UNKNOWN</option><option>NOT_KNOWN</option><option>CONFIRMED</option></select></label>
<label>業務重要度 <select name="business_criticality"><option value="">不明</option>@foreach(['LOW','MEDIUM','HIGH','CRITICAL'] as $v)<option>{{ $v }}</option>@endforeach</select></label><br>
<label><input type="checkbox" name="production_exposed" value="1">本番インターネット公開</label><br><label>緩和策 <textarea name="mitigations"></textarea></label><br><button>登録</button></form>@endsection
