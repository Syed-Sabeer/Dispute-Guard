<div class="actions" aria-label="Pagination">
@if($paginator->previousPageUrl())<s-link href="{{ $paginator->previousPageUrl() }}">Previous</s-link>@endif
<s-text>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }} · {{ $paginator->total() }} results</s-text>
@if($paginator->nextPageUrl())<s-link href="{{ $paginator->nextPageUrl() }}">Next</s-link>@endif
</div>
