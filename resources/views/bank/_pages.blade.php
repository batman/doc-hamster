@if ($pages->hasPages())
    <nav class="pager" aria-label="{{ __('bank.pages') }}">
        @if (! $pages->onFirstPage())
            <a class="button button-quiet button-small" href="{{ $pages->previousPageUrl() }}"><x-icon name="chevron-left" />{{ __('bank.previous') }}</a>
        @endif
        @if ($pages->hasMorePages())
            <a class="button button-quiet button-small" href="{{ $pages->nextPageUrl() }}">{{ __('bank.next') }}<x-icon name="chevron-right" /></a>
        @endif
    </nav>
@endif
