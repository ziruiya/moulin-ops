@extends('layouts.app')
@section('content')
<h1>{{ $asset->name }}?{{ $asset->asset_code }}?</h1>
<p>{{ $asset->asset_type }}?{{ $asset->environment }}?{{ $asset->operational_status }}?{{ $asset->criticality }}</p>
<p>????: {{ $asset->vendor ?? '???' }}????????: {{ $asset->support_contact ?? '???' }}</p>
<a href="{{ route('assets.edit', $asset) }}">??</a>
<h2>???????</h2>
@foreach($asset->components as $c)
<section><p>{{ $c->component_type }}?{{ $c->vendor_name }} {{ $c->product_name }} {{ $c->version }}?{{ $c->support_phase }}?????: {{ $c->currentLifecycle?->support_phase ?? '???' }}</p>
@foreach($c->lifecycleRecords as $l)<p>???????: <a href="{{ $l->source_url }}">{{ $l->source_name }}</a> {{ $l->verified_at }} {{ $l->support_phase }}</p>@endforeach
<form method="post" action="{{ route('assets.components.update', [$asset, $c]) }}">@csrf @method('PUT')
<input name="component_type" value="{{ $c->component_type }}" placeholder="??"><input name="vendor_name" value="{{ $c->vendor_name }}" placeholder="????"><input name="product_name" value="{{ $c->product_name }}" placeholder="???"><input name="version" value="{{ $c->version }}" placeholder="?????">
<select name="support_phase">@foreach(['Active','Security Only','Extended','EOL','Unknown'] as $phase)<option @selected($c->support_phase === $phase)>{{ $phase }}</option>@endforeach</select><button>??????????</button></form></section>
@endforeach
<form method="post" action="{{ route('assets.components.store', $asset) }}">@csrf<input name="component_type" placeholder="??"><input name="vendor_name" placeholder="????"><input name="product_name" placeholder="???"><input name="version" placeholder="?????"><select name="support_phase"><option>Unknown</option><option>Active</option><option>Security Only</option><option>Extended</option><option>EOL</option></select><button>?????????</button></form>
<h2>???????????</h2><form method="post" action="{{ route('assets.lifecycle.store', $asset) }}">@csrf<select name="component_id">@foreach($asset->components as $c)<option value="{{ $c->id }}">{{ $c->product_name }}</option>@endforeach</select><input name="source_url" placeholder="??URL"><input name="source_name" placeholder="???"><input type="datetime-local" name="verified_at"><select name="support_phase"><option>Unknown</option><option>Active</option><option>Security Only</option><option>Extended</option><option>EOL</option></select><select name="date_precision"><option>DAY</option><option>MONTH</option><option>QUARTER</option><option>UNKNOWN</option></select><input name="data_source_type" value="OFFICIAL_MANUAL_SEED"><button>???????</button></form>
<h2>????</h2>@foreach($asset->relationships as $rel)<p>{{ $rel->relationship_type }} ? {{ $rel->targetAsset->asset_code }} {{ $rel->targetAsset->name }} <form method="post" action="{{ route('assets.relationships.destroy', [$asset,$rel]) }}">@csrf @method('DELETE')<button>??</button></form></p>@endforeach
<form method="post" action="{{ route('assets.relationships.store', $asset) }}">@csrf<select name="target_asset_id">@foreach($targetAssets as $target)<option value="{{ $target->id }}">{{ $target->asset_code }} ? {{ $target->name }}</option>@endforeach</select><select name="relationship_type"><option>Depends on</option><option>Runs on</option><option>Hosted on</option><option>Connects to</option></select><button>????</button></form>
@endsection
