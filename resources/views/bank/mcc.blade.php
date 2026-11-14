{{-- Einstellungen → Bank → Kartenkategorien (A300): which merchant category code suggests which tag for the document
     linked to a card payment. Saving an existing code changes its tag. --}}
@extends('layouts.settings')

@section('title', __('bank.mcc_title'))

@section('page')
    <p class="muted">{{ __('bank.mcc_intro') }}</p>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <ul class="pick-list">
        @foreach ($entries as $entry)
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon name="credit-card" /></span>
                <span><strong>{{ $entry->mcc }}</strong></span>
                <span class="pill">#{{ $entry->tag }}</span>
                <span class="row-actions">
                    <form method="post" action="{{ route('bank.mcc.destroy', $entry->id) }}">
                        @csrf
                        <button type="submit" class="control-button" aria-label="{{ __('bank.mcc_delete') }}: {{ $entry->mcc }}" data-tooltip="{{ __('bank.mcc_delete') }}"><x-icon name="trash" /></button>
                    </form>
                </span>
            </li>
        @endforeach
    </ul>

    <form method="post" action="{{ route('bank.mcc.store') }}">
        @csrf
        <div class="field">
            <label for="mcc-code">{{ __('bank.mcc_code') }}</label>
            <input id="mcc-code" name="mcc" inputmode="numeric" pattern="\d{4}" maxlength="4" required value="{{ old('mcc') }}">
        </div>
        <div class="field">
            <label for="mcc-tag">{{ __('bank.mcc_tag') }}</label>
            <input id="mcc-tag" name="tag" maxlength="60" required value="{{ old('tag') }}">
        </div>
        <button type="submit"><x-icon name="plus" /> {{ __('bank.mcc_add') }}</button>
    </form>
@endsection
