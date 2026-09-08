@extends('layouts.app')
@section('title','Set up '.config('chargeguard.name'))
@section('content')
<s-section heading="Your setup checklist"><table><tbody>
<tr><td>Connected to Shopify</td><td><s-badge tone="success">Connected</s-badge></td></tr>
<tr><td><s-link href="/settings/email-sender">Configure sender name and email</s-link></td><td>{{ $shop->emailSender ? 'Saved' : 'To do' }}</td></tr>
<tr><td><s-link href="/settings/email-sender">Authenticate your sending domain</s-link></td><td>{{ app(\App\Services\Email\MerchantSenderService::class)->ready($shop) ? 'Verified' : (config('senders.required') ? 'To do' : 'Optional') }}</td></tr>
<tr><td><s-link href="/settings">Configure support and reply-to email</s-link></td><td>{{ $settings->support_email ? 'Ready' : 'To do' }}</td></tr>
<tr><td><s-link href="/templates">Review all 20 automation templates</s-link></td><td>{{ $settings->templates_reviewed_at ? 'Reviewed' : 'To do' }}</td></tr>
@if(\App\Services\DeploymentMode::testTools())<tr><td><s-link href="/test-automation">Send an optional test email</s-link></td><td>{{ $testSent ? 'Sent' : 'Optional' }}</td></tr>@endif
@if(config('chargeguard.billing_enabled'))<tr><td><s-link href="/billing">Choose or verify a billing plan</s-link></td><td>{{ $shop->billing_status }}</td></tr>@endif
<tr><td><s-link href="/settings">Explicitly activate automation</s-link></td><td>{{ $settings->auto_email_enabled ? 'Enabled' : 'Disabled' }}</td></tr>
</tbody></table></s-section>
<s-banner>Only new disputes can trigger an automatic email. Enabling automation will not email customers for historical disputes.</s-banner>
@endsection
