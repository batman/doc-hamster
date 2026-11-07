@extends('layouts.settings')

@section('title', __('roles.title'))

@section('page')
    <div class="page-head"><h1>{{ __('roles.title') }}</h1></div>
    <p class="muted">{{ __('roles.admin_hint') }}</p>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->has('password') ? __('exports.password_wrong') : $errors->first() }}</p>
    @endif

    <ul class="documents">
        @foreach ($roles as $role)
            <li>
                <form method="post" action="{{ route('roles.update', $role->id) }}">
                    @csrf
                    <label for="name-{{ $role->id }}">{{ __('roles.name') }}</label>
                    <input id="name-{{ $role->id }}" name="name" value="{{ $role->name }}" maxlength="64" required>
                    <fieldset class="rights">
                        <legend>{{ __('roles.rights_title') }}</legend>
                        @foreach ($rights as $right)
                            <label><input type="checkbox" name="rights[]" value="{{ $right->value }}" @checked($role->has($right))> {{ __('roles.rights.'.$right->value) }}</label>
                        @endforeach
                    </fieldset>
                    <p class="muted">
                        {{ trans_choice('roles.people', $role->memberships_count, ['count' => $role->memberships_count]) }}
                        @if ($role->isPreinstalled())
                            · {{ __('roles.preinstalled') }}
                        @endif
                    </p>
                    <label for="password-{{ $role->id }}">{{ __('exports.password') }}</label>
                    <input id="password-{{ $role->id }}" type="password" name="password" autocomplete="current-password" required>
                    <div class="actions">
                        <button type="submit">{{ __('roles.save') }}</button>
                        @if (! $role->isPreinstalled() && $role->memberships_count === 0)
                            <button type="submit" class="button-danger" formaction="{{ route('roles.destroy', $role->id) }}">{{ __('roles.delete') }}</button>
                        @endif
                    </div>
                </form>
            </li>
        @endforeach
    </ul>

    <h2>{{ __('roles.add') }}</h2>
    <form method="post" action="{{ route('roles.store') }}">
        @csrf
        <label for="name">{{ __('roles.name') }}</label>
        <input id="name" name="name" value="{{ old('name') }}" maxlength="64" required>
        <fieldset class="rights">
            <legend>{{ __('roles.rights_title') }}</legend>
            @foreach ($rights as $right)
                <label><input type="checkbox" name="rights[]" value="{{ $right->value }}"> {{ __('roles.rights.'.$right->value) }}</label>
            @endforeach
        </fieldset>
        <label for="password">{{ __('exports.password') }}</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        <button type="submit">{{ __('roles.create') }}</button>
    </form>
@endsection
