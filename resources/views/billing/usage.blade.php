<s-section heading="Automated follow-up usage">
@if($usage ?? null)
<s-heading>{{ $usage['name'] }}</s-heading>
<s-paragraph>{{ number_format($usage['used']) }} / {{ number_format($usage['allowance']) }} automated follow-ups used</s-paragraph>
<s-paragraph>{{ number_format($usage['remaining']) }} remaining@if($usage['reserved']) · {{ number_format($usage['reserved']) }} reserved for queued or in-flight messages@endif</s-paragraph>
<progress max="100" value="{{ $usage['percentage'] }}" aria-label="Follow-up allowance committed"></progress>
<s-paragraph>{{ $usage['percentage'] }}% committed · Resets {{ $usage['resets_at']->format('M j, Y H:i') }} UTC</s-paragraph>
@if($usage['remaining'] === 0)<s-banner tone="warning">Quota exhausted. New disputes require manual review. Your automation preference remains enabled if you selected it.</s-banner>@endif
@else
<s-paragraph>Usage period not yet verified. Automatic follow-ups require a current subscription period or an explicitly assigned private validation period.</s-paragraph>
@endif
<s-link href="/plans">Upgrade plan</s-link>
</s-section>
