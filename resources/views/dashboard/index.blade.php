@extends('layouts.app')
@section('title','Dashboard')
@section('content')
<div class="actions"><s-paragraph>Monitor disputes and help customers resolve their concerns.</s-paragraph><s-button href="/test-automation">Send a test email</s-button></div>
<div class="metrics">
@foreach($metrics as $label=>$value)<s-section heading="{{ $label }}"><div class="metric-value">{{ $value }}</div></s-section>@endforeach
<s-section heading="Revenue at risk">
@forelse($risk as $amount)<div class="metric-value">{{ $amount->total }} {{ $amount->currency }}</div>@empty<div class="metric-value">0</div>@endforelse
<s-text>Needs response and under review; totals stay separate by currency.</s-text>
</s-section>
</div>
<s-section heading="Recent disputes">@include('disputes.table')<div class="actions"><s-link href="/disputes">View all disputes</s-link></div></s-section>
@endsection
