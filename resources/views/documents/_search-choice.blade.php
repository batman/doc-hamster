{{-- Umschalter as a choice in the search panel (points 135, 188; core/button-choice.js): a hidden field with the address
     name and one segment per option ("" = Alle / the default). `label` names it in the section summary. --}}
<span class="button-choice" data-choice @if ($toggle) data-choice-toggle @endif @if ($label) data-summary-label="{{ $label }}" @endif>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    <span class="switcher" role="group" @if ($label) aria-label="{{ $label }}" @endif>
        @foreach ($options as $key => $text)
            <button type="button" data-value="{{ $key }}" aria-pressed="{{ (string) $key === $value ? 'true' : 'false' }}">{{ $text }}</button>
        @endforeach
    </span>
</span>
