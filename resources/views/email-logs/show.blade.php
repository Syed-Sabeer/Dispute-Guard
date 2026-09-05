@extends('layouts.app')
@section('title','Email result')
@section('content')
<s-section heading="{{ $log->subject ?: 'Email content unavailable' }}"><p>{{ $log->recipient_masked ?: 'Redacted' }} · {{ $log->status }} · {{ strtoupper($log->type) }}</p>
@if($log->error_message)<s-banner tone="warning">{{ $log->error_message }}</s-banner>@endif
<div class="preview">{!! app(\App\Services\Email\TemplateRenderer::class)->sanitize($log->rendered_body ?: '<p>No retained email content.</p>') !!}</div>
<p>Sent at: {{ $log->sent_at?->toDateTimeString() ?: 'Not confirmed' }} (UTC)</p></s-section>
@endsection
