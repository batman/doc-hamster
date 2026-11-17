{{-- Blättern (point 4, PO 2026-11-17 "keine Textlinks"): below a paged list ‹ "Seite 2 von 12" › – chevron icon buttons,
     each disabled when that page does not exist. data-window-reload="page": in a window only that window reloads with the
     page (core/window-search.js); without JavaScript a plain link. Takes a paginator (simple or with total). --}}
@props(['pages'])
@if ($pages->hasPages())
    @php
        $last = $pages instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $pages->lastPage() : null;
    @endphp
    <nav class="pager" aria-label="{{ __('app.pager.label') }}">
        @if ($pages->onFirstPage())
            <button type="button" class="icon-button" disabled aria-label="{{ __('app.pager.previous') }}"><x-icon name="chevron-left" /></button>
        @else
            <a class="icon-button" href="{{ $pages->previousPageUrl() }}" data-window-reload="page" aria-label="{{ __('app.pager.previous') }}" data-tooltip="{{ __('app.pager.previous') }}"><x-icon name="chevron-left" /></a>
        @endif
        <span class="pager-page">{{ $last !== null ? __('app.pager.page_of', ['page' => $pages->currentPage(), 'last' => $last]) : __('app.pager.page', ['page' => $pages->currentPage()]) }}</span>
        @if ($pages->hasMorePages())
            <a class="icon-button" href="{{ $pages->nextPageUrl() }}" data-window-reload="page" aria-label="{{ __('app.pager.next') }}" data-tooltip="{{ __('app.pager.next') }}"><x-icon name="chevron-right" /></a>
        @else
            <button type="button" class="icon-button" disabled aria-label="{{ __('app.pager.next') }}"><x-icon name="chevron-right" /></button>
        @endif
    </nav>
@endif
