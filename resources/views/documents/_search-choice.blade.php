{{-- Button group as a choice in the search panel (point 135, A283; core/button-choice.js): a hidden field with the address
     name and one small button per option ("" = Alle / the default). `label` names it in the section summary. --}}
<span class="button-choice" data-choice @if ($toggle) data-choice-toggle @endif @if ($label) data-summary-label="{{ $label }}" @endif>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    <span class="button-group" role="group" @if ($label) aria-label="{{ $label }}" @endif>
        @foreach ($options as $key => $text)
            <button type="button" class="button-quiet button-small" data-value="{{ $key }}" aria-pressed="{{ (string) $key === $value ? 'true' : 'false' }}">{{ $text }}</button>
        @endforeach
    </span>
</span>
