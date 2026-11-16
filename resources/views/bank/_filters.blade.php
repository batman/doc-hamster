{{-- The account window's filters (A304, A311): status with counts, Eingänge/Ausgänge and Liste/Kalender as Umschalter,
     each option with its symbol (PO 2026-11-16). When one does not fit the window, the framework (core/button-choice.js)
     shows it as its own dropdown – no hand-made narrow copy. The same block rides along in the window body as a template,
     so a search or reload of the body brings fresh counts (A326, point 149). --}}
@php
    $statusIcons = ['' => null, 'open' => 'receipt-off', 'suggested' => 'focus-2', 'linked' => 'link', 'none' => 'file-off'];
    $flowIcons = ['' => null, 'in' => 'arrow-down-left', 'out' => 'arrow-up-right'];
    $viewNow = $calendar ? 'calendar' : 'list';
@endphp
<div class="bank-filters" data-bank-filters>
    <div class="switcher" role="group" aria-label="{{ __('bank.status_filter') }}">
        @foreach ($statusOptions as $value => [$label, $count])
            <a href="{{ $with(['status' => $value === '' ? null : $value]) }}" data-bank-filter data-label="{{ $label }}" aria-pressed="{{ ($status ?? '') === $value ? 'true' : 'false' }}">@if ($statusIcons[$value] ?? null)<x-icon :name="$statusIcons[$value]" />@endif{{ $label }}@if ($count) <span class="pill">{{ $count }}</span>@endif</a>
        @endforeach
    </div>
    <div class="switcher" role="group" aria-label="{{ __('bank.flow') }}">
        @foreach ($flowOptions as $value => $label)
            <a href="{{ $with(['flow' => $value === '' ? null : $value]) }}" data-bank-filter aria-pressed="{{ ($flow ?? '') === $value ? 'true' : 'false' }}">@if ($flowIcons[$value] ?? null)<x-icon :name="$flowIcons[$value]" />@endif{{ $label }}</a>
        @endforeach
    </div>
    {{-- 159.2: Liste | Kalender like the view switch of Dokumente. --}}
    <div class="switcher" role="group" aria-label="{{ __('bank.view') }}">
        @foreach ($viewOptions as $value => $icon)
            <a href="{{ $with(['view' => $value === 'list' ? null : $value, 'month' => null]) }}" data-bank-filter aria-pressed="{{ $viewNow === $value ? 'true' : 'false' }}"><x-icon :name="$icon" />{{ __('bank.view_'.$value) }}</a>
        @endforeach
    </div>
</div>
