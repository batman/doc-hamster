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

    {{-- A388: "+ Kartenkategorie hinzufügen" at the top (Aufklapp-Plus, row variant): the code, then the tag with ✓. --}}
    @php $adding = $errors->any() && (old('mcc') !== null || old('tag') !== null); @endphp
    <form method="post" action="{{ route('bank.mcc.store') }}" class="expand-plus is-row @if ($adding) is-open is-filled @endif" data-expand-plus>
        @csrf
        <button type="button" class="list-entry expand-plus-open"><x-icon name="plus" /><span>{{ __('bank.mcc_new') }}</span></button>
        <div class="pick-row is-static expand-plus-row">
            <input class="input-small" name="mcc" inputmode="numeric" pattern="\d{4}" maxlength="4" required value="{{ old('mcc') }}" placeholder="{{ __('bank.mcc_code') }}" aria-label="{{ __('bank.mcc_code') }}" autocomplete="off" data-expand-focus>
            <div class="field-addon expand-plus-field">
                <input class="input-small" name="tag" maxlength="60" value="{{ old('tag') }}" placeholder="{{ __('bank.mcc_tag') }}" aria-label="{{ __('bank.mcc_tag') }}" autocomplete="off">
                <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="{{ __('bank.mcc_create') }}" data-tooltip="{{ __('bank.mcc_create') }}" @unless ($adding) hidden @endunless><x-icon name="check" /></button></span>
            </div>
        </div>
    </form>

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
@endsection
