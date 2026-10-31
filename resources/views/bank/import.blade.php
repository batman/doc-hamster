@extends('layouts.app')

@section('title', __('bank.import'))

@section('content')
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
        <input id="file" type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required>
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
        <input id="name" name="name" value="{{ old('name') }}" maxlength="150" @required($accounts->isEmpty())>
        @error('name')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="currency">{{ __('bank.currency') }}</label>
        <input id="currency" name="currency" value="{{ old('currency', 'EUR') }}" maxlength="3">
        @error('currency')<p class="error" role="alert">{{ $message }}</p>@enderror

        <button type="submit">{{ __('bank.next') }}</button>
    </form>

    @if ($accounts->isNotEmpty())
        <h2>{{ __('bank.accounts') }}</h2>
        <ul class="documents">
            @foreach ($accounts as $account)
                <li>
                    {{ $account->name }}
                    <span class="muted">{{ $account->currency }} · {{ trans_choice('bank.transactions_count', $account->transactions_count, ['count' => $account->transactions_count]) }}</span>
                    <form method="post" action="{{ route('bank.accounts.destroy', $account->id) }}">
                        @csrf
                        <button type="submit">{{ __('bank.delete_account') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
