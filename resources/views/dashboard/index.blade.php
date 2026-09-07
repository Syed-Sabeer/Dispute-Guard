@extends('layouts.app')
@section('title','Dashboard')
@section('content')
<s-paragraph>Monitor disputes and help customers resolve their concerns.</s-paragraph>
<s-section heading="Automation">
@if($shop->settings?->auto_email_enabled && $shop->settings?->onboarded_at && !config('chargeguard.test_mode') && !$shop->settings?->test_mode)
<s-badge tone="success">Enabled</s-badge><s-paragraph>Eligible new disputes receive customer follow-ups after safety checks.</s-paragraph>
@else
<s-badge>Disabled</s-badge><s-paragraph>Automatic customer follow-ups are currently disabled.</s-paragraph>
@endif
<s-link href="/settings">Manage automation settings</s-link>
</s-section>
<div class="metrics">
@foreach($metrics as $label=>$value)<s-section heading="{{ $label }}"><div class="metric-value">{{ $value }}</div></s-section>@endforeach
<s-section heading="Revenue at risk">
@forelse($risk as $amount)<div class="metric-value">{{ $amount->total }} {{ $amount->currency }}</div>@empty<div class="metric-value">0</div>@endforelse
<s-text>Needs response and under review; totals stay separate by currency.</s-text>
</s-section>
</div>
<s-section heading="Recent disputes">@include('disputes.table')<div class="actions"><s-link href="/disputes">View all disputes</s-link></div></s-section>
@endsection
