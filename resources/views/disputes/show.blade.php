@extends('layouts.app')
@section('title','Dispute '.($dispute->order_name ?: '#'.$dispute->id))
@section('content')
<div class="actions"><s-link href="/disputes">All disputes</s-link>
@if($dispute->shopify_order_id)<s-link target="_top" href="https://admin.shopify.com/store/{{ $shop->handle() }}/orders/{{ basename($dispute->shopify_order_id) }}">Open Shopify order</s-link>@endif
<s-badge>{{ strtoupper($dispute->source) }}</s-badge></div>
@if($dispute->review_reason)<s-banner tone="warning" heading="Review required">{{ $dispute->review_reason }}</s-banner>@endif
<div class="form-grid"><s-section heading="Dispute"><dl>
<dt>Order</dt><dd>{{ $dispute->order_name ?: 'Unavailable' }}</dd>
<dt>Reason</dt><dd>{{ str_replace('_',' ',$dispute->reason) }}</dd>
<dt>Status</dt><dd>{{ str_replace('_',' ',$dispute->status) }}</dd>
<dt>Amount</dt><dd>{{ $dispute->amount }} {{ $dispute->currency }}</dd>
<dt>Initiated</dt><dd>{{ $dispute->initiated_at?->toDateTimeString() ?: 'Unavailable' }}</dd>
<dt>Evidence due</dt><dd>{{ $dispute->evidence_due_at?->toDateTimeString() ?: 'Unavailable' }}</dd>
</dl><s-text>Evidence submission is handled outside this app.</s-text></s-section>
<s-section heading="Shipment"><dl>
<dt>State</dt><dd>{{ str_replace('_',' ',$dispute->shipping_state) }}</dd><dt>Raw carrier state</dt><dd>{{ $dispute->raw_shipping_status }}</dd>
<dt>Carrier</dt><dd>{{ $dispute->tracking_company ?: 'Unavailable' }}</dd><dt>Tracking</dt><dd>{{ $dispute->tracking_number ?: 'Unavailable' }}</dd>
</dl>
@if($dispute->tracking_url && in_array(parse_url($dispute->tracking_url,PHP_URL_SCHEME),['http','https'],true))<s-link href="{{ $dispute->tracking_url }}" target="_blank">Open carrier tracking</s-link>@endif
</s-section></div>
<s-section heading="Automation"><dl><dt>Result</dt><dd>{{ str_replace('_',' ',$dispute->automation_status) }}</dd><dt>Email sent</dt><dd>{{ $dispute->email_sent_at?->toDateTimeString() ?: 'Not sent' }}</dd>
@foreach($dispute->automationDeliveries as $delivery)<dt>Matched template</dt><dd>{{ $delivery->dispute_reason }} / {{ $delivery->shipping_state }} · {{ $delivery->template?->enabled ? 'Enabled' : 'Disabled' }} · {{ $delivery->status }}</dd>@endforeach
</dl>
@foreach($dispute->emailLogs as $log)<p>{{ $log->subject }} · {{ $log->recipient_masked ?: 'Redacted' }} · <s-link href="/email-logs/{{ $log->id }}">View email</s-link></p>@endforeach
</s-section>
<s-section heading="Refund context">
@forelse($dispute->refunds ?? [] as $refund)
<p>{{ data_get($refund,'totalRefundedSet.shopMoney.amount') }} {{ data_get($refund,'totalRefundedSet.shopMoney.currencyCode') }} · {{ $refund['createdAt'] ?? 'Date unavailable' }}</p>
@foreach(data_get($refund,'transactions.nodes',[]) as $transaction)<p>{{ $transaction['kind'] }}: {{ $transaction['status'] }} · {{ $transaction['processedAt'] ?? '' }}</p>@endforeach
@if(data_get($refund,'transactions.pageInfo.hasNextPage'))<s-text>Additional transactions require review in Shopify.</s-text>@endif
@empty<p>Refund information unavailable / requires review.</p>@endforelse
<s-text>A refund record alone does not prove funds reached the customer.</s-text>
</s-section>
<s-section heading="Sync"><p>Last synced: {{ $dispute->last_synced_at?->toDateTimeString() ?: 'Never' }} (UTC)</p>
<form data-api-form action="/disputes/{{ $dispute->id }}/resync"><button>Resync dispute</button></form><p class="muted">Resync updates information without sending another automatic email.</p></s-section>
@endsection
