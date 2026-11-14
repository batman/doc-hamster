@extends('layouts.settings')

@section('title', __('members.title'))

@section('page')

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($fresh !== null)
        {{-- The new password at once in an open dialog with copy fields (no flash: other requests would eat it). --}}
        <div class="dialog-backdrop" data-dialog="member-password">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="member-password-title">
                <h2 id="member-password-title">{{ __('members.password_title', ['name' => $fresh['name']]) }}</h2>
                <p class="card-text"><strong>{{ __('members.password_once', ['name' => $fresh['name']]) }}</strong></p>
                @include('settings._device-copy', ['id' => 'member-username', 'label' => __('auth.username'), 'value' => $fresh['username']])
                @include('settings._device-copy', ['id' => 'member-password', 'label' => __('auth.password'), 'value' => $fresh['password']])
                <div class="dialog-actions">
                    <button type="button" data-dialog-close>{{ __('members.done') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <ul class="documents">
        @foreach ($memberships as $membership)
            <li>
                <strong>{{ $membership->user?->display_name }}</strong>
                <span class="muted">{{ $membership->roleName() }} · {{ $membership->user?->username }}</span>
                {{-- A378: restored from a backup without any way to sign in – "Passwort zurücksetzen" sets it up again. --}}
                @if ($membership->isActive() && $membership->user !== null && $membership->user->password_hash === null && ! $membership->user->passkeys()->exists())
                    <span class="pill pill-warning">{{ __('members.no_access') }}</span>
                @endif
                @if (! $membership->isActive())
                    <span class="muted">· {{ __('members.removed') }}</span>
                @elseif ($membership->user_id !== $selfId)
                    <form method="post" action="{{ route('members.reset', $membership->id) }}" data-confirm-sudo>
                        @csrf
                        <label for="role-{{ $membership->id }}">{{ __('members.role') }}</label>
                        <select id="role-{{ $membership->id }}" name="role">
                            <option value="{{ $adminValue }}" @selected($membership->org_role_id === null)>{{ __('members.roles.owner') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected($membership->org_role_id === $role->id)>{{ $role->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" formaction="{{ route('members.role', $membership->id) }}">{{ __('members.change_role') }}</button>
                        <button type="submit">{{ __('members.reset') }}</button>
                        <button type="submit" formaction="{{ route('members.revoke', $membership->id) }}">{{ __('members.revoke') }}</button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>

    <h2>{{ __('members.add') }}</h2>
    <form method="post" action="{{ route('members.store') }}" data-confirm-sudo>
        @csrf
        <label for="display_name">{{ __('members.name') }}</label>
        <input id="display_name" name="display_name" value="{{ old('display_name') }}" required>
        <label for="username">{{ __('auth.username') }}</label>
        <input id="username" name="username" value="{{ old('username') }}" autocomplete="off" required>
        <label for="role">{{ __('members.role') }}</label>
        <select id="role" name="role">
            @foreach ($roles as $role)
                <option value="{{ $role->id }}" @selected(old('role') === $role->id)>{{ $role->name }}</option>
            @endforeach
            <option value="{{ $adminValue }}" @selected(old('role') === $adminValue)>{{ __('members.roles.owner') }}</option>
        </select>
        <button type="submit">{{ __('members.create') }}</button>
    </form>
@endsection
