@extends('layouts.app')
@section('title','Test Automation')
@section('content')
<s-banner tone="warning" heading="TEST MODE">This sends a labelled sample to the test address you enter. It does not create a Shopify dispute.</s-banner>
<s-section heading="Sample order and shipment"><form data-api-form action="/test-automation/send"><div class="form-grid">
<label>Dispute reason<select name="dispute_reason">@foreach(\App\Enums\DisputeReason::cases() as $reason)<option value="{{ $reason->value }}" @selected(request('dispute_reason')===$reason->value)>{{ $reason->label() }}</option>@endforeach</select></label>
<label>Shipping state<select name="shipment_status">@foreach(\App\Enums\OrderShippingState::automatic() as $state)<option value="{{ $state->value }}" @selected(request('shipment_status')===$state->value)>{{ $state->label() }}</option>@endforeach</select></label>
<label>Test customer name<input name="customer_name" required maxlength="100" value="Sample customer"></label>
<label>Test email<input type="email" name="customer_email" required maxlength="254" autocomplete="email"></label>
<label>Order number<input name="order_number" required value="#TEST-1001"></label>
<label>Order amount<input name="order_amount" required value="49.00" inputmode="decimal"></label>
<label>Currency<input name="currency" required pattern="[A-Z]{3}" value="{{ $shop->currency ?: 'USD' }}" maxlength="3"></label>
<label>Carrier<input name="carrier" maxlength="100" value="Sample carrier"></label>
<label>Tracking number<input name="tracking_number" maxlength="100" value="TEST123"></label>
<label>Tracking URL<input type="url" name="tracking_url" maxlength="2000" value="https://example.com/tracking/TEST123"></label>
<label>Store name<input name="store_name" required maxlength="150" value="{{ $shop->settings->store_display_name ?: $shop->store_name ?: config('chargeguard.name') }}"></label>
</div><button>Send test email</button></form></s-section>
@endsection
