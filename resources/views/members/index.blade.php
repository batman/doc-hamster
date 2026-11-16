{{-- Einstellungen → Personen (points 215, 218, 223) as an Aufklappliste (FRAMEWORK.md): "+ Person hinzufügen" (Aufklapp-
     Plus als Zeile: name, user name, role, optional access dates) → the one-time password in a dialog (ShownOnce). A click
     opens a person: role, "Zugang von / bis", a new one-time password, "Pausiert", "Zuletzt angesehen", "Zugang entfernen".
     The own row only shows. --}}
@extends('layouts.settings')

@section('title', __('members.title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    @if ($fresh !== null)
        {{-- The one-time password at once in an open dialog with copy fields (no flash: other requests would eat it). --}}
        <div class="dialog-backdrop" data-dialog="member-password">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="member-password-title">
                <h2 id="member-password-title">{{ __('members.password_title', ['name' => $fresh['name']]) }}</h2>
                <p class="card-text"><strong>{{ __('members.password_once', ['name' => $fresh['name']]) }}</strong></p>
                @include('settings._device-copy', ['id' => 'member-username', 'label' => __('auth.username'), 'value' => $fresh['username']])
                @include('settings._device-copy', ['id' => 'member-password', 'label' => __('auth.password'), 'value' => $fresh['password']])
            </div>
        </div>
    @endif

    @php
        $adding = old('display_name') !== null && $errors->any();
        $roleOptions = [$adminValue => __('members.roles.owner'), ...$roles->pluck('name', 'id')->all()];
    // PO 2026-11-16: the symbols of the Rollen rows – Admin a star, every role the shield.
    $roleIcons = array_map(static fn ($key): string => $key === $adminValue ? 'star' : 'user-shield', array_combine(array_keys($roleOptions), array_keys($roleOptions)));
        $newRole = (string) old('role', $roles->first()?->id ?? $adminValue);
    @endphp
    <form method="post" action="{{ route('members.store') }}" class="expand-plus is-row @if ($adding) is-open is-filled @endif" data-expand-plus data-confirm-sudo>
        @csrf
        <button type="button" class="list-entry expand-plus-open"><x-icon name="plus" /><span>{{ __('members.add') }}</span></button>
        <div class="pick-row is-static expand-plus-row">
            <div class="field-addon expand-plus-field">
                <input class="input-small" name="display_name" maxlength="100" value="{{ old('display_name') }}" placeholder="{{ __('members.name') }}" aria-label="{{ __('members.name') }}" autocomplete="off" data-username-from="username">
                <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="{{ __('members.create') }}" data-tooltip="{{ __('members.create') }}" @unless ($adding) hidden @endunless><x-icon name="check" /></button></span>
            </div>
            <input class="input-small" name="username" maxlength="64" value="{{ old('username') }}" placeholder="{{ __('members.username_hint') }}" aria-label="{{ __('auth.username') }}" autocomplete="off" data-username-field>
            @include('documents._search-choice', ['name' => 'role', 'value' => $newRole, 'options' => $roleOptions, 'icons' => $roleIcons, 'toggle' => false, 'label' => __('members.role')])
            <input class="input-small" type="date" name="access_from" value="{{ old('access_from') }}" aria-label="{{ __('members.access_from') }}" data-tooltip="{{ __('members.access_from') }}">
            <input class="input-small" type="date" name="access_until" value="{{ old('access_until') }}" aria-label="{{ __('members.access_until') }}" data-tooltip="{{ __('members.access_until') }}">
        </div>
    </form>

    <ul class="accordion-list">
        @foreach ($memberships as $membership)
            @include('members._person', ['isOpen' => $open === $membership->id])
        @endforeach
    </ul>
@endsection
