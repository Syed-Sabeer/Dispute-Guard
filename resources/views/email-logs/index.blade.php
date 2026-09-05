@extends('layouts.app')
@section('title','Email Logs')
@section('content')
<div class="actions"><s-link href="/email-logs">All</s-link><s-link href="/email-logs?type=automatic">Automatic</s-link><s-link href="/email-logs?type=test">Tests</s-link></div>
<s-section><div class="scroll"><table><thead><tr><th>Date</th><th>Order / combination</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Type</th></tr></thead><tbody>
@forelse($logs as $log)<tr><td>{{ $log->created_at->format('M j H:i') }}</td><td>{{ $log->dispute?->order_name ?: 'Sample / unavailable' }}<br>{{ str_replace('_',' ',$log->dispute_reason) }}<br>{{ str_replace('_',' ',$log->shipping_state) }}</td><td>{{ $log->recipient_masked ?: 'Redacted' }}</td><td><s-link href="/email-logs/{{ $log->id }}">{{ $log->subject ?: 'View email result' }}</s-link></td><td>{{ $log->status }}</td><td><s-badge>{{ strtoupper($log->type) }}</s-badge></td></tr>
@empty<tr><td colspan="6">No emails yet. Send a test email to check your template and sending setup.</td></tr>@endforelse
</tbody></table></div>@include('layouts.pagination',['paginator'=>$logs])</s-section>
@endsection
