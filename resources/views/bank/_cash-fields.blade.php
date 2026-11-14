{{-- Point 190: the fields of a cash entry – date, Einnahme | Ausgabe, amount, text. $prefix makes the ids unique,
     $cashEntry (optional) fills them for editing. --}}
@php
    $cashEntry ??= null;
    $flowValue = $cashEntry !== null && $cashEntry->amount_minor > 0 ? 'in' : 'out';
@endphp
<div class="field">
    <label for="{{ $prefix }}-date">{{ __('bank.cash_date') }}</label>
    <input id="{{ $prefix }}-date" type="date" name="date" value="{{ $cashEntry?->booking_date->format('Y-m-d') ?? now()->format('Y-m-d') }}" required>
</div>
<fieldset>
    <legend>{{ __('bank.cash_flow') }}</legend>
    <span class="button-choice" data-choice>
        <input type="hidden" name="flow" value="{{ $flowValue }}">
        <span class="switcher" role="group" aria-label="{{ __('bank.cash_flow') }}">
            <button type="button" data-value="in" aria-pressed="{{ $flowValue === 'in' ? 'true' : 'false' }}">{{ __('bank.cash_in') }}</button>
            <button type="button" data-value="out" aria-pressed="{{ $flowValue === 'out' ? 'true' : 'false' }}">{{ __('bank.cash_out') }}</button>
        </span>
    </span>
</fieldset>
<div class="field">
    <label for="{{ $prefix }}-amount">{{ __('bank.cash_amount') }}</label>
    <input id="{{ $prefix }}-amount" name="amount" inputmode="decimal" maxlength="30" placeholder="0,00" required
           value="{{ $cashEntry !== null ? number_format(abs($cashEntry->amount_minor) / 100, 2, ',', '.') : '' }}">
</div>
<div class="field cash-entry-text">
    <label for="{{ $prefix }}-text">{{ __('bank.cash_text') }}</label>
    <input id="{{ $prefix }}-text" name="text" maxlength="255" required placeholder="{{ __('bank.cash_text_placeholder') }}" value="{{ $cashEntry?->purpose }}">
</div>
