@extends('layouts.app')
@section('title','Customer data requests')
@section('content')
<s-banner>Download the prepared data and securely provide it to the verified requester. Mark the request fulfilled once delivered. This app does not automatically email privacy exports.</s-banner>
<s-section><table><thead><tr><th>Received</th><th>Status</th><th>Actions</th></tr></thead><tbody>
@forelse($requests as $item)<tr><td>{{ $item->created_at }}</td><td>{{ $item->status }}</td><td>
@if($item->status==='READY')<s-link href="/privacy-requests/{{ $item->id }}/export">Download export</s-link><form data-api-form action="/privacy-requests/{{ $item->id }}/complete"><button data-confirm="Confirm the requested data has been securely provided to the verified requester.">Mark fulfilled</button></form>@endif
</td></tr>@empty<tr><td colspan="3">No customer data requests.</td></tr>@endforelse</tbody></table>@include('layouts.pagination',['paginator'=>$requests])</s-section>
@endsection
