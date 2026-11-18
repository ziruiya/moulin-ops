@extends('layouts.app')

@section('content')
<main>
    <h1>{{ $asset->exists ? 'IT資産の編集' : 'IT資産の登録' }}</h1>
    <form method="post" action="{{ $asset->exists ? route('assets.update', $asset) : route('assets.store') }}">
        @csrf
        @if($asset->exists)@method('PUT')@endif
        @foreach(['asset_code' => '資産コード', 'name' => '名称', 'asset_type' => '種類', 'environment' => '環境', 'criticality' => '重要度', 'vendor' => 'ベンダー', 'support_contact' => '外部サポート連絡先', 'vendor_ticket_ref' => '外部チケット参照', 'inventory_source' => '棚卸し情報源'] as $field => $label)
            <p><label>{{ $label }} <input name="{{ $field }}" value="{{ old($field, $asset->$field) }}" @required(in_array($field, ['asset_code', 'name', 'asset_type']))></label></p>
        @endforeach
        <p><label>稼働状態
            <select name="operational_status">
                @foreach(['Active' => '稼働中', 'Degraded' => '一部停止', 'Maintenance' => 'メンテナンス中', 'Retired' => '廃止済み'] as $status => $label)
                    <option value="{{ $status }}" @selected(old('operational_status', $asset->operational_status) === $status)>{{ $label }}</option>
                @endforeach
            </select>
        </label></p>
        <p><label>棚卸し確認状況
            <select name="inventory_status">
                @foreach(['Unverified' => '未確認', 'Verified' => '確認済み', 'Stale' => '期限超過', 'Unknown' => '不明', 'Conflict' => '情報源が不一致'] as $status => $label)
                    <option value="{{ $status }}" @selected(old('inventory_status', $asset->inventory_status) === $status)>{{ $label }}</option>
                @endforeach
            </select>
        </label></p>
        <p><label>棚卸し確認日時（{{ config('app.timezone') }}） <input type="datetime-local" name="last_verified_at" value="{{ old('last_verified_at', $asset->last_verified_at?->format('Y-m-d\TH:i')) }}"></label></p>
        <input type="hidden" name="internet_exposed" value="0">
        <p><label><input type="checkbox" name="internet_exposed" value="1" @checked(old('internet_exposed', $asset->internet_exposed))> インターネット公開</label></p>
        <p><label>概要 <textarea name="description">{{ old('description', $asset->description) }}</textarea></label></p>
        <button>保存</button>
    </form>
</main>
@endsection
