{{-- Paginazione semplice (precedente/successiva) --}}
@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Paginazione">
        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true"><span class="page-link"><i class="bi bi-chevron-left"></i> Precedente</span></li>
            @else
                <li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev"><i class="bi bi-chevron-left"></i> Precedente</a></li>
            @endif
            @if ($paginator->hasMorePages())
                <li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Successiva <i class="bi bi-chevron-right"></i></a></li>
            @else
                <li class="page-item disabled" aria-disabled="true"><span class="page-link">Successiva <i class="bi bi-chevron-right"></i></span></li>
            @endif
        </ul>
    </nav>
@endif
