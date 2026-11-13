{{--
    Foldable card ("Karte, klappbar", point 125, A260/A261; docs/design/FRAMEWORK.md):
    <x-fold-card fold="ocr.mode" :title="__('ocr.mode_label')" icon="text-scan-2" folded summary="App-eigen" summary-tone="ok">
        <x-slot:action><form class="card-action">…</form></x-slot:action>
        …body…
    </x-fold-card>
    `fold` = page.card id (the person's own toggle is remembered per id, cookie dh_folds), `folded` = the page's
    start state. `summary` (+ `summary-tone` ok|warning, `summary-icon`) shows in the head only while folded.
    `compact` = the small variant for sections inside a panel (point 135, e.g. the search panel): smaller head, no frame,
    the summary always shown (empty = hidden; a module may update `[data-card-summary] > span` live).
    `:remember="false"` = the page's state every time (no cookie), e.g. "folded unless a filter in it is set".
--}}
@props(['fold', 'title', 'icon' => null, 'folded' => false, 'summary' => null, 'summaryTone' => null, 'summaryIcon' => null, 'compact' => false, 'remember' => true])
@php
    $isFolded = $remember ? \App\Http\CardFolds::folded((string) $fold, (bool) $folded) : (bool) $folded;
    $summaryIcon ??= match ($summaryTone) { 'ok' => 'circle-check', 'warning' => 'alert-triangle', default => null };
    $label = __($isFolded ? 'app.card_unfold' : 'app.card_fold');
@endphp
<section {{ $attributes->class(['card card-fold', 'is-compact' => $compact, 'is-folded' => $isFolded]) }} data-card-fold="{{ $fold }}" @unless ($remember) data-card-fold-forget @endunless>
    <h2 class="card-head">
        @if ($icon)<x-icon :name="$icon" />@endif<span class="card-title">{{ $title }}</span>
        @if ($summary !== null && ($summary !== '' || $compact))
            <span @class(['card-summary', 'is-'.$summaryTone => $summaryTone !== null]) data-card-summary @if ($summary === '') hidden @endif>@if ($summaryIcon)<x-icon :name="$summaryIcon" />@endif<span>{{ $summary }}</span></span>
        @endif
        {{ $action ?? '' }}
        <button type="button" class="control-button card-fold-button" data-card-fold-toggle aria-expanded="{{ $isFolded ? 'false' : 'true' }}"
            aria-label="{{ $label }}" data-tooltip="{{ $label }}" data-label-fold="{{ __('app.card_fold') }}" data-label-unfold="{{ __('app.card_unfold') }}"><x-icon name="chevron-down" /></button>
    </h2>
    <div class="card-body" @if ($isFolded) hidden @endif>{{ $slot }}</div>
</section>
