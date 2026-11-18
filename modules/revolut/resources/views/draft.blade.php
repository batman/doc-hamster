{{-- Point 38: a payment draft at Revolut from this document – filled from its recognised values; docHamster only
     prepares it, a person checks and sends it in the Revolut Business app. Opened from the document's ⋯ menu. --}}
@extends('layouts.app')

@section('title', __('m-revolut::texts.draft_action'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
        <x-window :title="__('m-revolut::texts.draft_action')" icon="send" kind="m-revolut-draft" :close="route('documents.show', $document->id)" fit="narrow">
            @if (session('status'))
                <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
            @endif
            @if (session('error'))
                <p class="error" role="alert">{{ session('error') }}</p>
            @endif
            <p class="muted">{{ __('m-revolut::texts.draft_intro') }}</p>
            <form method="post" action="{{ route('m.revolut.draft.create', $document->id) }}">
                @csrf
                <div class="field">
                    <label for="draft-account">{{ __('m-revolut::texts.draft_from') }}</label>
                    <select id="draft-account" name="account_id" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account['id'] }}" @selected(old('account_id') === $account['id'])>{{ \DocHamsterModules\Revolut\Sync::name($account) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="draft-name">{{ __('m-revolut::texts.draft_name') }}</label>
                    <input id="draft-name" name="name" value="{{ old('name', $values['name']) }}" maxlength="80" required>
                </div>
                <div class="field">
                    <label for="draft-iban">IBAN</label>
                    <input id="draft-iban" name="iban" value="{{ old('iban', $values['iban']) }}" maxlength="40" required autocomplete="off">
                </div>
                <div class="field">
                    <label for="draft-bic">{{ __('m-revolut::texts.draft_bic') }}</label>
                    <input id="draft-bic" name="bic" value="{{ old('bic') }}" maxlength="11" autocomplete="off">
                </div>
                <div class="field">
                    <label for="draft-amount">{{ __('m-revolut::texts.draft_amount_label') }}</label>
                    <div class="field-row"><input id="draft-amount" name="amount" value="{{ old('amount', $values['amount']) }}" inputmode="decimal" maxlength="20" required><input name="currency" value="{{ old('currency', $values['currency']) }}" maxlength="3" aria-label="{{ __('m-revolut::texts.draft_currency') }}" required></div>
                </div>
                <div class="field">
                    <label for="draft-reference">{{ __('m-revolut::texts.draft_reference') }}</label>
                    <input id="draft-reference" name="reference" value="{{ old('reference', $values['reference']) }}" maxlength="140" required>
                </div>
                <div class="actions">
                    <button type="submit"><x-icon name="send" />{{ __('m-revolut::texts.draft_create') }}</button>
                </div>
            </form>
        </x-window>
    </div>
@endsection
