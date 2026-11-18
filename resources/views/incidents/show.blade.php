@extends('layouts.app')
@section('content')
<h1>{{ $incident->incident_code }} {{ $incident->title }}</h1>
<p class="demo">DEMO DATA｜想定環境｜実在企業の構成ではありません</p>
@if($incident->reopenedFrom)<p>再開元: <a href="{{ route('incidents.show',$incident->reopenedFrom) }}">{{ $incident->reopenedFrom->incident_code }}</a>（原記録は変更されません）</p>@endif
@if($incident->reopenings->isNotEmpty())<h2>再開した関連障害</h2><ul>@foreach($incident->reopenings as $reopened)<li><a href="{{ route('incidents.show',$reopened) }}">{{ $reopened->incident_code }}</a></li>@endforeach</ul>@endif
<p>状態 {{ ['NEW'=>'新規','TRIAGE'=>'初動確認','INVESTIGATING'=>'調査中','MITIGATING'=>'影響軽減中','MONITORING'=>'監視中','RESOLVED'=>'復旧済み','CLOSED'=>'クローズ','ON_HOLD'=>'保留','REOPENED'=>'再開','CANCELLED'=>'取消'][$incident->status] }} / 重要度 {{ ['CRITICAL'=>'重大','HIGH'=>'高','MEDIUM'=>'中','LOW'=>'低'][$incident->severity] }} / 優先度 {{ ['P1'=>'最優先 (P1)','P2'=>'高 (P2)','P3'=>'中 (P3)','P4'=>'低 (P4)'][$incident->priority] }} / version {{ $incident->version }}</p>
@if($incident->status!=='CLOSED' && auth()->user()->roles()->whereIn('name',['Admin','Operator','Reviewer'])->exists())<h2>障害情報・調査の更新</h2>@include('incidents._form',['action'=>route('incidents.update',$incident),'method'=>'PUT'])@endif
<h2>障害の詳細</h2>
<dl>@foreach(['category'=>'分類','summary'=>'事件概要','user_impact'=>'利用者への影響','business_impact'=>'業務への影響','scope_description'=>'影響範囲の説明','detection_method'=>'検知方法','initial_hypothesis'=>'初期仮説','workaround'=>'暫定対応','recurrence_prevention'=>'再発防止策','escalation_reason'=>'エスカレーション理由','acknowledged_at'=>'受付日時','started_investigation_at'=>'調査開始日時','description'=>'補足説明','source'=>'発生源','symptoms'=>'症状','web_findings'=>'Web 調査','application_findings'=>'アプリケーション調査','database_findings'=>'DB 調査','network_findings'=>'ネットワーク調査','root_cause'=>'根本原因','resolution'=>'復旧結果','resolution_verification'=>'復旧検証','closure_reason'=>'クローズ理由','detected_at'=>'検知日時','reported_at'=>'報告日時','started_at'=>'対応開始日時','mitigated_at'=>'影響軽減日時','resolved_at'=>'復旧日時','closed_at'=>'クローズ日時','resolution_verified_at'=>'検証日時','vendor_ticket_ref'=>'ベンダー問い合わせ番号','related_vulnerability_id'=>'関連脆弱性 ID'] as $field=>$label)<dt>{{ $label }}</dt><dd>{{ $incident->$field }}</dd>@endforeach</dl>
<p>担当者 {{ $incident->assignee?->name }} / 検証者 {{ $incident->verifier?->name }} / {{ $incident->self_verified ? '自己検証あり' : '自己検証なし' }}</p>
<h3>影響範囲</h3><ul>@foreach($incident->assets as $asset)<li>{{ $asset->asset_code }} {{ $asset->name }}</li>@endforeach @foreach($incident->components as $component)<li>{{ $component->component_type }} {{ $component->product_name }}</li>@endforeach @foreach($incident->updatePlans as $plan)<li>{{ $plan->plan_code }} {{ $plan->title }}</li>@endforeach</ul>
<h2>状態変更</h2>
@if(auth()->user()->roles()->whereIn('name',['Admin','Operator','Reviewer'])->exists())
<form method="post" action="{{ route('incidents.transition',$incident) }}">@csrf<input type="hidden" name="version" value="{{ $incident->version }}">
<label>次の状態 <select name="status">@foreach(['TRIAGE'=>'初動確認','INVESTIGATING'=>'調査中','MITIGATING'=>'影響軽減中','MONITORING'=>'監視中','RESOLVED'=>'復旧済み','CLOSED'=>'クローズ','ON_HOLD'=>'保留','REOPENED'=>'再開','CANCELLED'=>'取消'] as $s=>$label)<option value="{{ $s }}">{{ $label }}</option>@endforeach</select></label>
<p><label>再開理由 <input name="reopen_reason"></label></p><p><label>クローズ理由 <textarea name="closure_reason"></textarea></label></p>
<p>クローズ前チェック（全項目確認必須）</p>@foreach(['check_restore'=>'復旧結果を確認','check_verification'=>'重要な検証を完了','check_work_record'=>'関連作業記録を確認','check_closure_reason'=>'クローズ理由を記入','check_follow_up'=>'後続 UpdatePlan の要否を確認'] as $k=>$v)<label><input type="checkbox" name="{{ $k }}" value="1">{{ $v }}</label><br>@endforeach
<p><label><input type="checkbox" name="follow_up_required" value="1">後続 UpdatePlan が必要</label></p>
<p>@foreach($plans as $p)<label><input type="checkbox" name="plan_ids[]" value="{{ $p->id }}">{{ $p->plan_code }} {{ $p->title }}</label><br>@endforeach</p>
<label>独立検証者（高リスクでは必須） <select name="resolution_verified_by"><option value="">選択</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></label><button>状態を更新</button></form>
@endif
<h2>運用記録</h2><a href="{{ route('work-records.index',['incident_id'=>$incident->id]) }}">この障害の記録を表示・追加</a><ul>@foreach($incident->workRecords as $wr)<li><a href="{{ route('work-records.show',$wr) }}">{{ $wr->record_code }}</a> {{ $wr->summary }}</li>@endforeach</ul>
<h2>監査履歴</h2><ol>@foreach($history as $e)<li>{{ $e->created_at }} — {{ $e->event_type }} {{ $e->old_values }} → {{ $e->new_values }}</li>@endforeach</ol>
<p><a href="{{ route('audit-events.index',['type'=>App\Models\Incident::class,'id'=>$incident->id]) }}">この障害の監査記録を検索</a></p>
<button type="button" onclick="document.getElementById('ai-demo').showModal()">AI調査テンプレート（DEMO）</button><dialog id="ai-demo"><h2>AI調査テンプレート（DEMO）</h2><p class="demo">DEMO ONLY｜架空のテンプレート例です。AI API には接続せず、自動判定・自動転記もしません。</p><h3>調査質問の例</h3><p>発生時刻・影響範囲・再現条件・直近変更・各層の観測事実を記録してください。</p><h3>出力形式</h3><p>確認済み事実／未確認事項／仮説／次の確認作業／根拠を分けて記載します。</p><label><input type="checkbox">人が内容を確認し、記録への反映を承認した</label><p>この確認は説明用です。反映前に担当者が内容を確認してください。</p><button type="button" onclick="this.closest('dialog').close()">閉じる</button></dialog>
@endsection
