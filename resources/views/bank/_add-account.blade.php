{{-- Points 189, 190: "+ Konto" in the Bank window's toolbar opens this dialog. Kind as Umschalter (Geschäft | Privat |
     Kasse); a bank account takes an optional IBAN (a later statement with it lands here), a cash box an opening balance
     instead (modules/bank.js shows the fields of the chosen kind). --}}
<div class="dialog-backdrop" data-dialog="add-account" hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="add-account-title">
        <h2 id="add-account-title">{{ __('bank.add_account_title') }}</h2>
        <form method="post" action="{{ route('bank.accounts.store') }}" data-bank-action data-account-create>
            @csrf
            <div class="field">
                <label for="add-account-name">{{ __('bank.name') }}</label>
                <input id="add-account-name" name="name" maxlength="150" required>
            </div>
            <fieldset>
                <legend>{{ __('bank.kind') }}</legend>
                <span class="button-choice" data-choice>
                    <input type="hidden" name="kind" value="business">
                    <span class="switcher" role="group" aria-label="{{ __('bank.kind') }}">
                        @foreach (\App\Domain\Banking\BankAccount::KINDS as $kindValue)
                            <button type="button" data-value="{{ $kindValue }}" aria-pressed="{{ $kindValue === 'business' ? 'true' : 'false' }}"><x-icon :name="\App\Domain\Banking\BankAccount::KIND_ICONS[$kindValue]" />{{ __('bank.kinds.'.$kindValue) }}</button>
                        @endforeach
                    </span>
                </span>
            </fieldset>
            <div class="field" data-account-kind-only="bank">
                <label for="add-account-iban">{{ __('bank.iban_optional') }}</label>
                <input id="add-account-iban" name="iban" maxlength="42" autocomplete="off" spellcheck="false">
                <p class="field-hint">{{ __('bank.add_account_hint') }}</p>
            </div>
            <div class="field" data-account-kind-only="cash" hidden>
                <label for="add-account-opening">{{ __('bank.opening') }}</label>
                <input id="add-account-opening" name="opening" inputmode="decimal" maxlength="30" placeholder="0,00">
                <p class="field-hint">{{ __('bank.opening_hint') }}</p>
            </div>
            <div class="field">
                <label for="add-account-currency">{{ __('bank.currency_short') }}</label>
                <input id="add-account-currency" name="currency" value="EUR" maxlength="3" required>
            </div>
            <div class="dialog-actions">
                <button type="submit">{{ __('bank.create') }}</button>
            </div>
        </form>
    </div>
</div>
