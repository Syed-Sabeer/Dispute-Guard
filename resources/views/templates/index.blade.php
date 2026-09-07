@extends('layouts.app')
@section('title','Email Templates')
@section('content')
<s-paragraph>Review the 20 reason and shipment combinations. Individual switches work together with your global automation setting.</s-paragraph>
@foreach($groups as $reason=>$templates)
<s-section heading="{{ ucwords(strtolower(str_replace('_',' ',$reason))) }}"><div class="scroll"><table><thead><tr><th>Shipping state</th><th>Status</th><th>Subject</th><th>Actions</th></tr></thead><tbody>
@foreach($templates as $template)<tr>
<td>{{ ucwords(strtolower(str_replace('_',' ',$template->shipping_state))) }}</td><td><s-badge>{{ $template->enabled ? 'Enabled' : 'Disabled' }}</s-badge></td><td>{{ $template->subject }}</td>
<td><s-link href="/templates/{{ $template->id }}/edit">Edit / preview</s-link>@if(\App\Services\DeploymentMode::testTools()) · <s-link href="/test-automation?dispute_reason={{ $template->dispute_reason }}&shipment_status={{ $template->shipping_state }}">Send test</s-link>@endif</td>
</tr>@endforeach</tbody></table></div></s-section>
@endforeach
@endsection
