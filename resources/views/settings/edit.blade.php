@extends('layouts.app')
@section('title','Settings')
@section('content')
<s-section heading="Customer communication"><form data-api-form data-method="PUT" action="/settings"><div class="form-grid">
<label>Store display name<input name="store_display_name" required maxlength="150" value="{{ $settings->store_display_name ?: $shop->store_name }}"></label>
<label>Support email<input name="support_email" type="email" required value="{{ $settings->support_email }}"></label>
<label>Reply-to email<input name="reply_to_email" type="email" value="{{ $settings->reply_to_email }}"></label>
<label>Timezone<select name="timezone">@foreach(timezone_identifiers_list() as $timezone)<option @selected($settings->timezone===$timezone)>{{ $timezone }}</option>@endforeach</select></label>
</div>
<label>Email footer<textarea name="email_footer" maxlength="2000" style="min-height:90px">{{ $settings->email_footer }}</textarea></label>
<label><input type="checkbox" name="templates_reviewed" @checked($settings->templates_reviewed_at)>I have reviewed the automation templates.</label>
<label><input type="checkbox" name="test_mode" @checked($settings->test_mode)>Test mode — block production automatic email</label>
<label><input type="checkbox" name="auto_email_enabled" @checked($settings->auto_email_enabled)>Activate automatic customer emails for new Shopify Payments disputes</label>
<s-paragraph>Activation requires a successful test email, reviewed templates, and a verified billing plan.</s-paragraph><div class="actions"><button>Save settings</button></div>
</form></s-section>
<s-section heading="Privacy"><s-link href="/privacy-requests">Customer data requests</s-link></s-section>
@endsection
