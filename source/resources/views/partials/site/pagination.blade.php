@if ($paginator->hasPages())
<nav class="pagination" aria-label="Pagination">
    @if ($paginator->onFirstPage())
        <span class="is-disabled" aria-disabled="true">‹ Prev</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Prev</a>
    @endif

    @foreach ($elements as $element)
        @if (is_string($element))
            <span class="is-disabled">{{ $element }}</span>
        @endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span class="is-active" aria-current="page">{{ $page }}</span>
                @else
                    <a href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>
    @else
        <span class="is-disabled" aria-disabled="true">Next ›</span>
    @endif
</nav>
@endif
