@extends('layouts.app')
@section('title','Dashboard')
@section('content')
<s-paragraph>Monitor disputes and help customers resolve their concerns.</s-paragraph>
<s-section heading="Automation">
@php($senders = app(\App\Services\Email\MerchantSenderService::class))
@php($senderReady = $senders->configured() && $senders->isVerified($shop))
@php($senderUnavailable = (bool) $shop->emailSender?->verification_refresh_failed_at)
@if($shop->settings?->auto_email_enabled && $shop->settings?->onboarded_at && !config('chargeguard.test_mode') && !$shop->settings?->test_mode && (!config('senders.required') || ($senderReady && !$senderUnavailable)))
<s-badge tone="success">Enabled</s-badge><s-paragraph>Eligible new disputes receive customer follow-ups after safety checks.</s-paragraph>
@elseif($shop->settings?->auto_email_enabled)
<s-badge>Automation temporarily paused</s-badge><s-paragraph>Customer emails are blocked until the required safety checks pass. Your automation preference is unchanged.</s-paragraph>
@else
<s-badge>Disabled</s-badge><s-paragraph>Automatic customer follow-ups are currently disabled.</s-paragraph>
@endif
<s-link href="/settings">Manage automation settings</s-link>
<s-paragraph>Email sender: {{ $senders->statusLabel($shop) }}</s-paragraph>
@if(config('senders.required') && !$senders->isVerified($shop))<s-paragraph>Verify your sending domain before activating customer emails.</s-paragraph><s-link href="/settings/email-sender">Configure email sender</s-link>@endif
@if($senderReady)<s-paragraph>Email sender: {{ $shop->emailSender->sender_name }} &lt;{{ $shop->emailSender->sender_email }}&gt; — Verified</s-paragraph>@endif
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
