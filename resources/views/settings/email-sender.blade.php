@extends('layouts.app')
@section('title', 'Email sender')
@section('content')
<s-section heading="Email sender">
<s-paragraph>Customer emails are sent from your verified business email. No DNS setup is required for standard sender verification.</s-paragraph>
@include('settings.sender-form')
</s-section>
@if($sender)
<s-section heading="Sender verification">
<s-paragraph>Status: {{ app(\App\Services\Email\MerchantSenderService::class)->statusLabel($shop) }}</s-paragraph>
@if($sender->sender_mode === 'SIGNATURE' && $sender->verification_status !== 'REMOVED')
<s-paragraph>Confirm both the Postmark email and the Dispute Guard mailbox ownership link. Ownership must be confirmed separately for each Shopify store.</s-paragraph>
<form data-api-form action="/settings/email-sender/resend"><button>{{ $sender->verification_sent_at ? 'Resend verification email' : 'Send verification email' }}</button></form>
@endif
@if($sender->verification_status !== 'REMOVED')
<form data-api-form action="/settings/email-sender/verify"><button>Check verification</button></form>
<form data-api-form action="/settings/email-sender/disconnect"><button data-confirm="Disconnect this sender and block customer and test automation emails?">Disconnect sender</button></form>
@endif
</s-section>
@endif
<details><summary>Advanced domain authentication</summary>
<s-paragraph>Optional DKIM + Return-Path authentication for stronger deliverability. Choose advanced authentication when saving your sender, then publish the records below. Customer emails still use your exact business email.</s-paragraph>
@if($sender && $sender->sender_mode === 'DOMAIN')
<s-section heading="Authenticate your sending domain">
    <dl><dt>Sending domain</dt><dd>{{ $sender->sendingDomain->domain }}</dd>
        <dt>Status</dt><dd><s-badge>{{ app(\App\Services\Email\MerchantSenderService::class)->statusLabel($shop) }}</s-badge></dd>
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
    <form data-api-form action="/settings/email-sender/disconnect"><button data-confirm="Disconnect this sender and block customer and test automation emails?">Disconnect sender</button></form>
    @endif
</s-section>
@endif
</details>
@endsection
