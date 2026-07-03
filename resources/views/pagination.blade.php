@if ($paginator->hasPages())
    <nav style="display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap;">
        @if ($paginator->onFirstPage())
            <span class="btn small ghost" style="opacity:.5;cursor:default;">‹ Zurück</span>
        @else
            <button type="button" class="btn small ghost" wire:click="previousPage" wire:loading.attr="disabled">‹ Zurück</button>
        @endif

        <span class="muted" style="font-size:13px;">Seite {{ $paginator->currentPage() }} von {{ $paginator->lastPage() }}</span>

        @if ($paginator->hasMorePages())
            <button type="button" class="btn small ghost" wire:click="nextPage" wire:loading.attr="disabled">Weiter ›</button>
        @else
            <span class="btn small ghost" style="opacity:.5;cursor:default;">Weiter ›</span>
        @endif
    </nav>
@endif
