<s-section heading="Automated dispute usage">
@if($usage ?? null)
<s-heading>{{ $usage['name'] }}</s-heading>
<s-paragraph>{{ number_format($usage['used']) }} / {{ number_format($usage['allowance']) }} automated disputes used</s-paragraph>
<s-paragraph>
    {{ number_format($usage['remaining']) }} remaining
    {{ ($usage['reserved'] ?? 0) > 0
        ? ' · '.number_format($usage['reserved']).' reserved for queued or in-flight automated disputes'
        : ''
    }}
</s-paragraph>
<progress max="100" value="{{ $usage['percentage'] }}" aria-label="Automated dispute allowance committed"></progress>
<s-paragraph>{{ $usage['percentage'] }}% committed · Resets {{ $usage['resets_at']->format('M j, Y H:i') }} UTC</s-paragraph>
@if($usage['remaining'] === 0)<s-banner tone="warning">Dispute automation limit reached. New eligible disputes require manual review.</s-banner>@endif
@else
<s-paragraph>Usage period not yet verified. Automated disputes require a current subscription period or an explicitly assigned private validation period.</s-paragraph>
@endif
<s-link href="/plans">Upgrade plan</s-link>
</s-section>
