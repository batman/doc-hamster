{{-- Point 210: the scope of a tax advisor's access – e-mail, period, accounts (incl. cash boxes), expiry. $access is null
     in the create dialog; $key makes the ids unique per dialog. --}}
@php
    $all = $access === null || $access->account_ids === null;
@endphp
<div class="field">
    <label for="advisor-email-{{ $key }}">{{ __('advisor.email') }}</label>
    <input id="advisor-email-{{ $key }}" type="email" name="email" maxlength="254" value="{{ $access?->user->email }}" autocomplete="off">
    <p class="field-hint">{{ $mailReady ? __('advisor.email_hint') : __('advisor.email_hint_no_mail') }}</p>
</div>
<fieldset>
    <legend>{{ __('advisor.period') }}</legend>
    <div class="search-row">
        <div class="field">
            <label for="advisor-from-{{ $key }}">{{ __('advisor.from') }}</label>
            <input id="advisor-from-{{ $key }}" type="date" name="period_from" value="{{ $access?->period_from?->toDateString() }}">
        </div>
        <div class="field">
            <label for="advisor-to-{{ $key }}">{{ __('advisor.to') }}</label>
            <input id="advisor-to-{{ $key }}" type="date" name="period_to" value="{{ $access?->period_to?->toDateString() }}">
        </div>
    </div>
    <p class="field-hint">{{ __('advisor.period_hint') }}</p>
</fieldset>
<fieldset>
    <legend>{{ __('advisor.accounts') }}</legend>
    <label class="check"><input type="checkbox" name="all_accounts" value="1" @checked($all)> <span><strong>{{ __('advisor.all_accounts') }}</strong></span></label>
    @foreach ($accounts as $account)
        <label class="check"><input type="checkbox" name="accounts[]" value="{{ $account->id }}" @checked(! $all && in_array($account->id, $access->account_ids ?? [], true))>
            <span><x-icon :name="$account->icon()" /> {{ $account->name }}</span></label>
    @endforeach
</fieldset>
<div class="field">
    <label for="advisor-expires-{{ $key }}">{{ __('advisor.expires') }}</label>
    <input id="advisor-expires-{{ $key }}" type="date" name="expires_at" value="{{ $access?->expires_at?->toDateString() }}">
    <p class="field-hint">{{ __('advisor.expires_hint') }}</p>
</div>
