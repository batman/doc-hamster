{{-- Einstellungen → Bank → Kein Beleg nötig (A286): the reasons as a sortable list; each row renames (✓ or Enter) and
     switches on/off (autosave); a reason nobody uses can be deleted, one in use only switched off. --}}
@extends('layouts.settings')

@section('title', __('bank.reasons_title'))

@section('page')
    <p class="muted">{{ __('bank.reasons_intro') }}</p>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <ul class="pick-list" data-sortable data-bank-order="{{ route('bank.reasons.order') }}" data-order-failed="{{ __('bank.order_failed') }}">
        @foreach ($reasons as $reason)
            <li class="pick-row is-static @unless ($reason->active) is-off @endunless" data-id="{{ $reason->id }}">
                <button type="button" class="pick-handle" aria-label="{{ __('bank.reason_drag', ['name' => $reason->name]) }}"><x-icon name="grip-vertical" /></button>
                <form method="post" action="{{ route('bank.reasons.update', $reason->id) }}" data-autosave class="row-field">
                    @csrf
                    <div class="field-addon">
                        <input class="input-small" name="name" value="{{ $reason->name }}" maxlength="100" required aria-label="{{ __('bank.reason_name') }}">
                        <span class="addon-end"><button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('bank.save') }}" data-tooltip="{{ __('bank.save') }}" hidden><x-icon name="check" /></button></span>
                    </div>
                    <noscript><button type="submit">{{ __('bank.save') }}</button></noscript>
                </form>
                @if ($used->has($reason->id))
                    <span class="pill">{{ __('bank.reason_used') }}</span>
                @endif
                <form method="post" action="{{ route('bank.reasons.update', $reason->id) }}" data-autosave>
                    @csrf
                    <input type="hidden" name="active" value="0">
                    <label class="check" data-tooltip="{{ __('bank.reason_active') }}"><input type="checkbox" name="active" value="1" @checked($reason->active) aria-label="{{ __('bank.reason_active') }}: {{ $reason->name }}"></label>
                    <noscript><button type="submit">{{ __('bank.save') }}</button></noscript>
                </form>
                <span class="row-actions">
                    @if ($used->has($reason->id))
                        <button type="button" class="control-button" disabled aria-label="{{ __('bank.reason_used_hint') }}" data-tooltip="{{ __('bank.reason_used_hint') }}"><x-icon name="trash" /></button>
                    @else
                        <form method="post" action="{{ route('bank.reasons.destroy', $reason->id) }}">
                            @csrf
                            <button type="submit" class="control-button" aria-label="{{ __('bank.reason_delete') }}: {{ $reason->name }}" data-tooltip="{{ __('bank.reason_delete') }}"><x-icon name="trash" /></button>
                        </form>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>

    <form method="post" action="{{ route('bank.reasons.store') }}">
        @csrf
        <div class="field">
            <label for="reason-new">{{ __('bank.reason_add') }}</label>
            <div class="field-addon">
                <input id="reason-new" name="name" maxlength="100" required value="{{ old('name') }}">
                <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="{{ __('bank.reason_add') }}" data-tooltip="{{ __('bank.reason_add') }}"><x-icon name="plus" /></button></span>
            </div>
        </div>
    </form>
@endsection
