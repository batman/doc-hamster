@if ($pages->hasPages())
    <nav class="tabs" aria-label="{{ __('bank.pages') }}">
        @if (! $pages->onFirstPage())
            <a href="{{ $pages->previousPageUrl() }}">{{ __('bank.previous') }}</a>
        @endif
        @if ($pages->hasMorePages())
            <a href="{{ $pages->nextPageUrl() }}">{{ __('bank.next') }}</a>
        @endif
    </nav>
@endif
