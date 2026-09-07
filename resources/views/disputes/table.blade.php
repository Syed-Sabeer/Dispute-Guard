<div class="scroll"><table><thead><tr><th>Order</th><th>Reason / status</th><th>Shipment</th><th>Amount</th><th>Automation</th><th>Received</th><th></th></tr></thead><tbody>
@forelse($disputes as $dispute)
<tr>
    <td>{{ $dispute->order_name ?: 'Order unavailable' }} @if($dispute->source !== 'shopify')<s-badge tone="warning">{{ strtoupper($dispute->source) }}</s-badge>@endif</td>
    <td>{{ ucwords(strtolower(str_replace('_',' ',$dispute->reason))) }}<br><span class="muted">{{ ucwords(strtolower(str_replace('_',' ',$dispute->status))) }}</span></td>
    <td><s-badge>{{ str_replace('_',' ',$dispute->shipping_state) }}</s-badge></td>
    <td>{{ $dispute->amount }} {{ $dispute->currency }}</td>
    <td>{{ str_replace('_',' ',$dispute->automation_status) }}</td><td>{{ $dispute->created_at->format('M j, Y') }}</td>
    <td><s-link href="/disputes/{{ $dispute->id }}">View</s-link></td>
</tr>
@empty
<tr><td colspan="7"><s-heading>{{ request()->is('disputes') ? (request('automation_status') === 'MANUAL_REVIEW' ? 'No disputes currently require manual review.' : 'No disputes found.') : 'No disputes yet' }}</s-heading><p>New Shopify Payments disputes will appear here automatically when they are received.</p></td></tr>
@endforelse
</tbody></table></div>
