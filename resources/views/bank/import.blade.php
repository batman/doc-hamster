@extends('layouts.settings')

@section('title', __('bank.import'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif

    @if ($confirm = session('confirm_delete'))
        {{-- Without JavaScript the first "Löschen" lands here: the same question as the dialog, shown at once. --}}
        <div class="dialog-backdrop" data-dialog="account-delete-confirm">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="account-delete-confirm-title">
                <h2 id="account-delete-confirm-title">{{ __('bank.delete_account') }}</h2>
                <p>{{ __('bank.confirm_delete', ['name' => $confirm['name'], 'count' => $confirm['count']]) }}</p>
                <form class="dialog-actions" method="post" action="{{ route('bank.accounts.destroy', $confirm['id']) }}">
                    @csrf
                    <input type="hidden" name="confirmed" value="1">
                    <button type="submit" class="button-danger">{{ __('bank.delete_account') }}</button>
                </form>
            </div>
        </div>
    @endif

    <p>{{ __('bank.import_hint') }}</p>

    <form method="post" action="{{ route('bank.import.upload') }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
            <label for="file">{{ __('bank.file') }}</label>
            <input id="file" type="file" name="file" accept=".csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            @error('file')<p class="error" role="alert">{{ $message }}</p>@enderror
        </div>

        @if ($accounts->isNotEmpty())
            <div class="field">
                <label for="account">{{ __('bank.account') }}</label>
                <select id="account" name="account">
                    <option value="">{{ __('bank.new_account') }}</option>
                    @foreach ($accounts as $account)
                        @continue($account->isCash())
                        <option value="{{ $account->id }}" @selected(old('account') === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="field @error('name') has-error @enderror">
            <label for="name">{{ __('bank.account_name') }}</label>
            <input id="name" name="name" value="{{ old('name') }}" maxlength="150">
            @error('name')<p class="field-hint" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="field @error('currency') has-error @enderror">
            <label for="currency">{{ __('bank.currency') }}</label>
            <input id="currency" name="currency" value="{{ old('currency', 'EUR') }}" maxlength="3">
            @error('currency')<p class="field-hint" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="field @error('iban') has-error @enderror">
            <label for="iban">{{ __('bank.iban') }}</label>
            <input id="iban" name="iban" value="{{ old('iban') }}" maxlength="42" autocomplete="off" spellcheck="false">
            <p class="field-hint">@error('iban'){{ $message }}@else{{ __('bank.iban_hint') }}@enderror</p>
        </div>

        <div class="actions"><button type="submit">{{ __('bank.next') }}</button></div>
    </form>

    @if ($accounts->isNotEmpty())
        <h2>{{ __('bank.accounts') }}</h2>
        {{-- PO 2026-11-16: framework rows – the name by click (Feld mit ✓), Geschäft | Privat saved at once, Löschen as a
             red row action that asks first. --}}
        <ul class="pick-list">
            @foreach ($accounts as $account)
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon :name="$account->icon()" /></span>
                    <span>
                        {{-- 142: the name comes from the bank; renaming stays possible. --}}
                        <strong><x-edit-in-place name="name" :value="$account->name" :action="route('bank.accounts.rename', $account->id)" :label="__('bank.account_name')" maxlength="150" /></strong>
                        <small>{{ $account->currency }}@if ($account->iban) · {{ trim(chunk_split($account->iban, 4, ' ')) }}@endif · {{ trans_choice('bank.transactions_count', $account->transactions_count, ['count' => $account->transactions_count]) }}</small>
                    </span>
                    {{-- Point 189: Geschäft | Privat, saved at once; a cash box stays one. --}}
                    @if ($account->isCash())
                        <span class="pill">{{ __('bank.kinds.cash') }}</span>
                    @else
                        <form method="post" action="{{ route('bank.accounts.kind', $account->id) }}" data-autosave>
                            @csrf
                            <span class="button-choice" data-choice>
                                <input type="hidden" name="kind" value="{{ $account->kind }}">
                                <span class="switcher" role="group" aria-label="{{ __('bank.kind') }}">
                                    @foreach ([\App\Domain\Banking\BankAccount::BUSINESS, \App\Domain\Banking\BankAccount::PRIVATE] as $kindValue)
                                        <button type="button" data-value="{{ $kindValue }}" aria-pressed="{{ $account->kind === $kindValue ? 'true' : 'false' }}"><x-icon :name="\App\Domain\Banking\BankAccount::KIND_ICONS[$kindValue]" />{{ __('bank.kinds.'.$kindValue) }}</button>
                                    @endforeach
                                </span>
                            </span>
                            <noscript><button type="submit">{{ __('bank.cash_save') }}</button></noscript>
                        </form>
                    @endif
                    <span class="row-actions">
                        <button type="button" class="control-button control-danger" data-dialog-open="account-delete-{{ $account->id }}" aria-label="{{ __('bank.delete_account') }}: {{ $account->name }}" data-tooltip="{{ __('bank.delete_account') }}"><x-icon name="trash" /></button>
                    </span>
                    <div class="dialog-backdrop" data-dialog="account-delete-{{ $account->id }}" hidden>
                        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="account-delete-{{ $account->id }}-title">
                            <h2 id="account-delete-{{ $account->id }}-title">{{ __('bank.delete_account') }}</h2>
                            <p>{{ __('bank.confirm_delete', ['name' => $account->name, 'count' => $account->transactions_count]) }}</p>
                            <form class="dialog-actions" method="post" action="{{ route('bank.accounts.destroy', $account->id) }}">
                                @csrf
                                <input type="hidden" name="confirmed" value="1">
                                <button type="submit" class="button-danger">{{ __('bank.delete_account') }}</button>
                            </form>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
