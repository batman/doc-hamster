{{-- The account window's filters (A304, A311): status with counts and Eingänge/Ausgänge as button groups; when they do
     not fit the window, modules/bank.js marks the window is-filters-tight and they show as dropdowns (point 150). The
     same block rides along in the window body as a template, so a search or reload of the body brings fresh counts
     (A326, point 149). --}}
<div class="bank-filters" data-bank-filters>
    <div class="button-group bank-filters-wide win-wide-only" role="group" aria-label="{{ __('bank.status_filter') }}">
        @foreach ($statusOptions as $value => [$label, $count])
            <a class="button button-quiet button-small" href="{{ $with(['status' => $value === '' ? null : $value]) }}" data-bank-filter aria-pressed="{{ ($status ?? '') === $value ? 'true' : 'false' }}">{{ $label }}@if ($count) <span class="pill">{{ $count }}</span>@endif</a>
        @endforeach
    </div>
    <div class="button-group bank-filters-wide win-wide-only" role="group" aria-label="{{ __('bank.flow') }}">
        @foreach ($flowOptions as $value => $label)
            <a class="button button-quiet button-small" href="{{ $with(['flow' => $value === '' ? null : $value]) }}" data-bank-filter aria-pressed="{{ ($flow ?? '') === $value ? 'true' : 'false' }}">{{ $label }}</a>
        @endforeach
    </div>
    <div class="dropdown bank-filters-narrow win-narrow-only">
        <button type="button" class="icon-button @if ($status) is-active @endif" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('bank.status_filter') }}: {{ $statusOptions[$status ?? ''][0] }}" data-tooltip="{{ __('bank.status_filter') }}"><x-icon name="filter" /></button>
        <div class="dropdown-panel" role="menu" hidden>
            @foreach ($statusOptions as $value => [$label, $count])
                <a class="menu-item" role="menuitemradio" aria-checked="{{ ($status ?? '') === $value ? 'true' : 'false' }}" href="{{ $with(['status' => $value === '' ? null : $value]) }}" data-bank-filter><span>{{ $label }}</span>@if ($count) <span class="pill">{{ $count }}</span>@endif</a>
            @endforeach
        </div>
    </div>
    <div class="dropdown bank-filters-narrow win-narrow-only">
        <button type="button" class="icon-button @if ($flow) is-active @endif" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('bank.flow') }}: {{ $flowOptions[$flow ?? ''] }}" data-tooltip="{{ __('bank.flow') }}"><x-icon name="arrows-left-right" /></button>
        <div class="dropdown-panel" role="menu" hidden>
            @foreach ($flowOptions as $value => $label)
                <a class="menu-item" role="menuitemradio" aria-checked="{{ ($flow ?? '') === $value ? 'true' : 'false' }}" href="{{ $with(['flow' => $value === '' ? null : $value]) }}" data-bank-filter><span>{{ $label }}</span></a>
            @endforeach
        </div>
    </div>
    {{-- 159.2: Liste | Kalender like the view switch of Dokumente; narrow one dropdown button. --}}
    @php $viewNow = $calendar ? 'calendar' : 'list'; @endphp
    <div class="button-group bank-filters-wide win-wide-only" role="group" aria-label="{{ __('bank.view') }}">
        @foreach ($viewOptions as $value => $icon)
            <a class="icon-button button-small" href="{{ $with(['view' => $value === 'list' ? null : $value, 'month' => null]) }}" data-bank-filter aria-pressed="{{ $viewNow === $value ? 'true' : 'false' }}" aria-label="{{ __('bank.view_'.$value) }}" data-tooltip="{{ __('bank.view_'.$value) }}"><x-icon :name="$icon" /></a>
        @endforeach
    </div>
    <div class="dropdown bank-filters-narrow win-narrow-only">
        <button type="button" class="icon-button" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('bank.view') }}: {{ __('bank.view_'.$viewNow) }}" data-tooltip="{{ __('bank.view') }}"><x-icon :name="$viewOptions[$viewNow]" /></button>
        <div class="dropdown-panel" role="menu" hidden>
            @foreach ($viewOptions as $value => $icon)
                <a class="menu-item" role="menuitemradio" aria-checked="{{ $viewNow === $value ? 'true' : 'false' }}" href="{{ $with(['view' => $value === 'list' ? null : $value, 'month' => null]) }}" data-bank-filter><x-icon :name="$icon" /><span>{{ __('bank.view_'.$value) }}</span></a>
            @endforeach
        </div>
    </div>
</div>
