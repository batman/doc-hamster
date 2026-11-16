{{-- Umschalter as a choice in the search panel and in forms (points 135, 188; core/button-choice.js): a hidden field with
     the address name and one segment per option ("" = Alle / the default). `label` names it in the section summary.
     `icons` (optional, value => Tabler name): the option's symbol before its text (PO 2026-11-16: symbol + text wherever a
     fitting symbol exists; periods and Alle/Ja/Nein stay text only). --}}
@php $icons ??= []; @endphp
<span class="button-choice" data-choice @if ($toggle ?? false) data-choice-toggle @endif @if ($label ?? null) data-summary-label="{{ $label }}" @endif>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    <span class="switcher" role="group" @if ($label ?? null) aria-label="{{ $label }}" @endif>
        @foreach ($options as $key => $text)
            <button type="button" data-value="{{ $key }}" data-label="{{ $text }}" aria-pressed="{{ (string) $key === (string) $value ? 'true' : 'false' }}">@if ($icons[$key] ?? null)<x-icon :name="$icons[$key]" />@endif{{ $text }}</button>
        @endforeach
    </span>
</span>
