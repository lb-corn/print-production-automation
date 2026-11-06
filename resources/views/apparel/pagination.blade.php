@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        <p class="pagination-summary">
            Showing {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </p>

        <div class="pagination-controls">
            @if ($paginator->onFirstPage())
                <span class="pagination-button disabled" aria-disabled="true" aria-label="Previous page">Previous</span>
            @else
                <a class="pagination-button" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">Previous</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pagination-ellipsis" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pagination-button active" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span>
                        @else
                            <a class="pagination-button" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="pagination-button" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">Next</a>
            @else
                <span class="pagination-button disabled" aria-disabled="true" aria-label="Next page">Next</span>
            @endif
        </div>
    </nav>
@elseif ($paginator->total() > 0)
    <div class="pagination single-page">
        <p class="pagination-summary">
            Showing {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </p>
    </div>
@endif
