@extends('layouts.app')
@section('title','Settings')
@section('content')
<s-section heading="Email sender"><s-paragraph>{{ app(\App\Services\Email\MerchantSenderService::class)->statusLabel($shop) }}</s-paragraph><s-paragraph>Authenticate your business domain to send customer emails from your own address.</s-paragraph><s-link href="/settings/email-sender">Configure email sender</s-link></s-section>
<s-section heading="Customer communication"><form data-api-form data-method="PUT" action="/settings"><div class="form-grid">
<label>Store display name<input name="store_display_name" required maxlength="150" value="{{ $settings->store_display_name ?: $shop->store_name }}"></label>
<label>Support email<input name="support_email" type="email" required value="{{ $settings->support_email }}"></label>
<label>Reply-to email<input name="reply_to_email" type="email" value="{{ $settings->reply_to_email }}"></label>
<label>Timezone<select name="timezone">@foreach(timezone_identifiers_list() as $timezone)<option @selected($settings->timezone===$timezone)>{{ $timezone }}</option>@endforeach</select></label>
</div>
<label>Email footer<textarea name="email_footer" maxlength="2000" style="min-height:90px">{{ $settings->email_footer }}</textarea></label>
<label><input type="checkbox" name="templates_reviewed" @checked($settings->templates_reviewed_at)>I have reviewed the automation templates.</label>
<label><input type="checkbox" name="test_mode" @checked($settings->test_mode)>Pause customer email delivery</label>
<label><input type="checkbox" name="auto_email_enabled" @checked($settings->auto_email_enabled)>Activate automatic customer emails for new Shopify Payments disputes</label>
<s-paragraph>Enabling automation allows real customer emails for eligible new disputes. Confirm your support address, review your templates, and turn off the delivery pause when ready.@if(config('chargeguard.billing_enabled')) An active subscription is also required.@endif</s-paragraph>
@if(config('chargeguard.test_mode'))<s-paragraph>Customer email delivery is temporarily paused by the app operator.</s-paragraph>@endif
<div class="actions"><button>Save settings</button></div>
</form></s-section>
<s-section heading="Privacy"><s-link href="/privacy-requests">Customer data requests</s-link></s-section>
@endsection
