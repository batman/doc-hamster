@extends('layouts.app')

@section('title', __('members.title'))

@section('content')
    <h1>{{ __('members.title') }}</h1>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($new = session('new_password'))
        <div class="notice" role="status">
            <p><strong>{{ __('members.password_once', ['name' => $new['name']]) }}</strong></p>
            <p>{{ __('auth.username') }}: <code>{{ $new['username'] }}</code></p>
            <p>{{ __('auth.password') }}: <code>{{ $new['password'] }}</code></p>
        </div>
    @endif

    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->has('password') ? __('exports.password_wrong') : $errors->first() }}</p>
    @endif

    <ul class="documents">
        @foreach ($memberships as $membership)
            <li>
                <strong>{{ $membership->user?->display_name }}</strong>
                <span class="muted">{{ __('members.roles.'.$membership->role->value) }} · {{ $membership->user?->username }}</span>
                @if (! $membership->isActive())
                    <span class="muted">· {{ __('members.removed') }}</span>
                @elseif ($membership->user_id !== $selfId)
                    <form method="post" action="{{ route('members.reset', $membership->id) }}">
                        @csrf
                        <label for="reset-{{ $membership->id }}">{{ __('exports.password') }}</label>
                        <input id="reset-{{ $membership->id }}" type="password" name="password" autocomplete="current-password" required>
                        <button type="submit">{{ __('members.reset') }}</button>
                        <button type="submit" formaction="{{ route('members.revoke', $membership->id) }}">{{ __('members.revoke') }}</button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>

    <h2>{{ __('members.add') }}</h2>
    <form method="post" action="{{ route('members.store') }}">
        @csrf
        <label for="display_name">{{ __('members.name') }}</label>
        <input id="display_name" name="display_name" value="{{ old('display_name') }}" required>
        <label for="username">{{ __('auth.username') }}</label>
        <input id="username" name="username" value="{{ old('username') }}" autocomplete="off" required>
        <label for="role">{{ __('members.role') }}</label>
        <select id="role" name="role">
            <option value="member" @selected(old('role') === 'member')>{{ __('members.roles.member') }} – {{ __('members.member_hint') }}</option>
            <option value="accountant" @selected(old('role') === 'accountant')>{{ __('members.roles.accountant') }} – {{ __('members.accountant_hint') }}</option>
        </select>
        <label for="password">{{ __('exports.password') }}</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        <button type="submit">{{ __('members.create') }}</button>
    </form>
@endsection
