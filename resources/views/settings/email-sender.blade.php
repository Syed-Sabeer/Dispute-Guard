@extends('layouts.app')
@section('title', 'Email sender')
@section('content')
<s-section heading="Send from your business email">
    <s-paragraph>Use an email address on a domain you own, such as support@yourstore.com. You must be able to edit its DNS records. No mailbox or SMTP password is needed.</s-paragraph>
    <form data-api-form data-method="PUT" action="/settings/email-sender">
        <div class="form-grid">
            <label>Sender name<input name="sender_name" required maxlength="100" value="{{ $sender?->sender_name ?? $shop->settings?->store_display_name }}"></label>
            <label>Sender email<input name="sender_email" required type="email" maxlength="254" value="{{ $sender?->sender_email }}"></label>
        </div>
        <s-paragraph>Changing your sending domain pauses automatic emails until you verify the new domain and enable automation again.</s-paragraph>
        <div class="actions"><button>Save sender</button><s-link href="/settings">Back to settings</s-link></div>
    </form>
</s-section>
@if($sender)
<s-section heading="Authenticate your sending domain">
    <dl><dt>Sending domain</dt><dd>{{ $sender->sendingDomain->domain }}</dd>
        <dt>Status</dt><dd><s-badge>{{ $sender->verification_status === 'VERIFIED' && app(\App\Services\Email\MerchantSenderService::class)->ready($shop) ? 'Verified' : ($sender->verification_status === 'REMOVED' ? 'Disconnected' : 'Verification required') }}</s-badge></dd>
        <dt>Last checked</dt><dd>{{ $sender->last_checked_at?->format('M j, Y H:i').' UTC' }}</dd></dl>
    @if($sender->verification_status !== 'REMOVED')
    <s-paragraph>Add these exact records to your domain's DNS. The ownership record links this store to the domain; stores sharing a domain each need their own record. Some DNS hosts automatically append your domain to the host field.</s-paragraph>
    <div class="scroll"><table><thead><tr><th>Purpose</th><th>Type</th><th>Host</th><th>Value</th></tr></thead><tbody>
        <tr><td>Store ownership</td><td>TXT</td><td><code>{{ $sender->ownership_host }}</code></td><td><code>{{ $sender->ownership_value }}</code></td></tr>
        <tr><td>DKIM</td><td>TXT</td><td><code>{{ $sender->sendingDomain->dkim_host ?: 'Save sender to retrieve records' }}</code></td><td><code>{{ $sender->sendingDomain->dkim_value }}</code></td></tr>
        <tr><td>Return-Path</td><td>CNAME</td><td><code>{{ $sender->sendingDomain->return_path_host }}</code></td><td><code>{{ $sender->sendingDomain->return_path_value }}</code></td></tr>
    </tbody></table></div>
    <s-paragraph>DNS changes can take time to propagate. After adding the records, click Check verification. Keep all records published while using this sender.</s-paragraph>
    <form data-api-form action="/settings/email-sender/verify"><div class="actions"><button>Check verification</button></div></form>
    <form data-api-form action="/settings/email-sender/disconnect"><button data-confirm="Disconnect this sender and disable automatic customer emails?">Disconnect sender</button></form>
    @endif
</s-section>
@endif
@endsection
