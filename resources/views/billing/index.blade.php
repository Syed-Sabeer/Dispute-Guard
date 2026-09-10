@extends('layouts.app')
@section('title','Billing')
@section('content')
@include('billing.usage')
@include('billing.plans')
<s-section heading="Shopify App Pricing"><dl><dt>Status</dt><dd>{{ $shop->billing_status }}</dd><dt>Plan item</dt><dd>{{ $shop->plan_handle ?: 'Not verified' }}</dd><dt>Last checked</dt><dd>{{ $shop->billing_checked_at?->toDateTimeString() ?: 'Not checked' }}</dd></dl>
@if(app()->environment(['local','testing']) && !config('chargeguard.billing_enforced'))<s-banner tone="warning">Development billing bypass is enabled.</s-banner>@elseif(!$entitled)<s-banner tone="warning">Automation requires a verified active subscription. Choose a plan or contact app support if verification remains unavailable.</s-banner>@endif
@if($manageUrl)<div class="actions"><s-button href="{{ $manageUrl }}" target="_top">Choose or manage plan</s-button></div>@else<p>The app operator needs to configure the Shopify app handle.</p>@endif
<s-link href="/billing">Refresh subscription status</s-link></s-section>
@endsection
