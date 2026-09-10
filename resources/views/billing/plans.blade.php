<s-section heading="Plans">
<div class="metrics">
@foreach(config('quotas.plans') as $key => $plan)
<s-section heading="{{ $plan['name'] }}">
<s-heading>${{ $plan['price'] }} / month</s-heading>
<s-paragraph>{{ $key === 'pro' ? 'Up to ' : '' }}{{ number_format($plan['allowance']) }} automated dispute follow-ups per billing period</s-paragraph>
</s-section>
@endforeach
</div>
<s-paragraph>Customer emails use your verified business email. Standard mailbox verification requires no DNS. Usage is independent for each store. Uncertain deliveries count; confirmed unsent cancellations do not. No automatic overage charges.</s-paragraph>
@if(config('chargeguard.billing_enabled') && ($manageUrl ?? null))
<s-button href="{{ $manageUrl }}" target="_top">Upgrade plan</s-button>
@else
<s-paragraph>Plan purchasing is unavailable during private validation. Contact app support for plan access.</s-paragraph>
@endif
</s-section>
