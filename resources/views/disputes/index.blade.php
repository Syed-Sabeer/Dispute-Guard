@extends('layouts.app')
@section('title','Disputes')
@section('content')
<s-section heading="Find disputes">
<form method="get" action="/disputes"><div class="form-grid">
<label>Order number<input name="search" value="{{ request('search') }}" maxlength="100"></label>
@foreach(['reason'=>array_column(\App\Enums\DisputeReason::cases(),'value'),'status'=>array_column(\App\Enums\DisputeStatus::cases(),'value'),'shipping_state'=>array_column(\App\Enums\OrderShippingState::cases(),'value'),'automation_status'=>array_column(\App\Enums\AutomationStatus::cases(),'value'),'source'=>['shopify','synthetic','demo']] as $field=>$options)
@continue(app()->environment('production') && $field === 'source')
<label>{{ ucwords(str_replace('_',' ',$field)) }}<select name="{{ $field }}"><option value="">All</option>@foreach($options as $option)<option value="{{ $option }}" @selected(request($field)===$option)>{{ ucwords(strtolower(str_replace('_',' ',$option))) }}</option>@endforeach</select></label>
@endforeach
<label>Received date<input type="date" name="date" value="{{ request('date') }}"></label>
<label>Sort<select name="sort"><option value="newest">Newest first</option><option value="oldest" @selected(request('sort')==='oldest')>Oldest first</option></select></label>
</div><button>Apply filters</button> <s-link href="/disputes">Clear</s-link></form></s-section>
<s-section>@include('disputes.table')@include('layouts.pagination',['paginator'=>$disputes])</s-section>
@endsection
