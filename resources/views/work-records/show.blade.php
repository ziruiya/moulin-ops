@extends('layouts.app')
@section('content')
<h1>{{ $workRecord->record_code }} — {{ $workRecord->summary }}</h1>
<p class="demo">DEMO DATA｜想定環境｜実在企業の構成ではありません</p>
<dl>@foreach(['record_type'=>'記録種別','action_summary'=>'実施作業','details'=>'詳細','command_summary'=>'コマンド概要','environment'=>'環境','before_state'=>'変更前','after_state'=>'変更後','result'=>'結果','error_detail'=>'エラー詳細','next_action'=>'次の対応','evidence_url'=>'証拠参照','started_at'=>'開始日時','ended_at'=>'終了日時','performed_at'=>'実施日時','created_at'=>'記録日時'] as $f=>$label)<dt>{{ $label }}</dt><dd>{{ $f === 'result' ? ['Success'=>'成功','Failed'=>'失敗','Partial'=>'一部完了','Pending'=>'保留'][$workRecord->$f] : ($f === 'record_type' ? ['Update'=>'更新','Backup'=>'バックアップ','Restore'=>'復元','Incident'=>'障害対応','Troubleshooting'=>'トラブルシューティング','Configuration'=>'設定変更','Verification'=>'検証','Vendor Communication'=>'ベンダー連絡'][$workRecord->$f] : $workRecord->$f) }}</dd>@endforeach</dl>
<p>記録者 {{ $workRecord->user->name }} / 資産 {{ $workRecord->asset->asset_code }} / 計画 {{ $workRecord->plan?->plan_code }} / 障害 {{ $workRecord->incident?->incident_code }}</p>
@if($workRecord->correction_of_id)<p>更正対象: <a href="{{ route('work-records.show',$workRecord->correctionOf) }}">{{ $workRecord->correctionOf->record_code }}</a> — {{ $workRecord->correction_reason }}</p>@endif
@if($workRecord->corrections->isNotEmpty())<h2>この記録の更正履歴</h2><ul>@foreach($workRecord->corrections as $correction)<li><a href="{{ route('work-records.show',$correction) }}">{{ $correction->record_code }}</a> — {{ $correction->correction_reason }}</li>@endforeach</ul>@endif
<p><a href="{{ route('work-records.index') }}">タイムラインへ</a></p>
<h2>監査履歴</h2><ol>@foreach($history as $e)<li>{{ $e->created_at }} — {{ $e->event_type }} {{ $e->old_values }} → {{ $e->new_values }}</li>@endforeach</ol>
@endsection
