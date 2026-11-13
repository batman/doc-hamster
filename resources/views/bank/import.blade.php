@extends('layouts.settings')

@section('title', __('bank.import'))

@section('page')
    <h1>{{ __('bank.import') }}</h1>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($confirm = session('confirm_delete'))
        <div class="notice" role="alert">
            <p><strong>{{ __('bank.confirm_delete', ['name' => $confirm['name'], 'count' => $confirm['count']]) }}</strong></p>
            <form method="post" action="{{ route('bank.accounts.destroy', $confirm['id']) }}">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <button type="submit">{{ __('bank.delete_account') }}</button>
            </form>
        </div>
    @endif

    <p>{{ __('bank.import_hint') }}</p>

    <form method="post" action="{{ route('bank.import.upload') }}" enctype="multipart/form-data">
        @csrf
        <label for="file">{{ __('bank.file') }}</label>
        <input id="file" type="file" name="file" accept=".csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        @error('file')<p class="error" role="alert">{{ $message }}</p>@enderror

        @if ($accounts->isNotEmpty())
            <label for="account">{{ __('bank.account') }}</label>
            <select id="account" name="account">
                <option value="">{{ __('bank.new_account') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected(old('account') === $account->id)>{{ $account->name }}</option>
                @endforeach
            </select>
        @endif

        <label for="name">{{ __('bank.account_name') }}</label>
        <input id="name" name="name" value="{{ old('name') }}" maxlength="150">
        @error('name')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="currency">{{ __('bank.currency') }}</label>
        <input id="currency" name="currency" value="{{ old('currency', 'EUR') }}" maxlength="3">
        @error('currency')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="iban">{{ __('bank.iban') }}</label>
        <input id="iban" name="iban" value="{{ old('iban') }}" maxlength="42" autocomplete="off" spellcheck="false">
        <p class="field-hint">{{ __('bank.iban_hint') }}</p>
        @error('iban')<p class="error" role="alert">{{ $message }}</p>@enderror

        <button type="submit">{{ __('bank.next') }}</button>
    </form>

    @if ($accounts->isNotEmpty())
        <h2>{{ __('bank.accounts') }}</h2>
        <ul class="documents">
            @foreach ($accounts as $account)
                <li>
                    {{-- 142: the name comes from the bank; renaming stays possible. --}}
                    <form method="post" action="{{ route('bank.accounts.rename', $account->id) }}">
                        @csrf
                        <div class="field-addon">
                            <input class="input-small" name="name" value="{{ $account->name }}" maxlength="150" required aria-label="{{ __('bank.account_name') }}">
                            <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="{{ __('bank.rename') }}" data-tooltip="{{ __('bank.rename') }}"><x-icon name="check" /></button></span>
                        </div>
                    </form>
                    <span class="muted">{{ $account->currency }}@if ($account->iban) · {{ trim(chunk_split($account->iban, 4, ' ')) }}@endif · {{ trans_choice('bank.transactions_count', $account->transactions_count, ['count' => $account->transactions_count]) }}</span>
                    <form method="post" action="{{ route('bank.accounts.destroy', $account->id) }}">
                        @csrf
                        <button type="submit">{{ __('bank.delete_account') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
