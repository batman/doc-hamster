{{-- Einstellungen → Personen → Rollen (points 215, 216, 223) as an Aufklappliste (FRAMEWORK.md): "+ Rolle hinzufügen"
     (Aufklapp-Plus als Zeile) above the list; a click opens a role: name (✓), rights in groups, restrictions (fold card),
     "Pausiert". Everything saves at once; delete via ⋯ in the row. Admin is fixed and only shown. --}}
@extends('layouts.settings')

@section('title', __('roles.title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <p class="muted">{{ __('roles.admin_hint') }}</p>

    @php $adding = old('name') !== null && $errors->has('name'); @endphp
    <form method="post" action="{{ route('roles.store') }}" class="expand-plus is-row @if ($adding) is-open is-filled @endif" data-expand-plus data-confirm-sudo>
        @csrf
        <button type="button" class="list-entry expand-plus-open"><x-icon name="plus" /><span>{{ __('roles.add') }}</span></button>
        <div class="pick-row is-static expand-plus-row">
            <div class="field-addon expand-plus-field">
                <input class="input-small" name="name" maxlength="64" value="{{ old('name') }}" placeholder="{{ __('roles.new_name') }}" aria-label="{{ __('roles.name') }}" autocomplete="off">
                <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="{{ __('roles.create') }}" data-tooltip="{{ __('roles.create') }}" @unless ($adding) hidden @endunless><x-icon name="check" /></button></span>
            </div>
        </div>
    </form>

    @php
        $byParent = collect($folders)->groupBy(static fn (array $row): string => $row['folder']->parent_id ?? '');
        $byId = collect($folders)->keyBy(static fn (array $row): string => $row['folder']->id);
    @endphp
    <ul class="accordion-list">
        <li class="accordion-row">
            <div class="accordion-head">
                <span class="accordion-static">
                    <span class="row-icon"><x-icon name="star" /></span>
                    <span><strong>{{ __('members.roles.owner') }}</strong><small>{{ trans_choice('roles.people', $admins, ['count' => $admins]) }} · {{ __('roles.admin_line') }}</small></span>
                </span>
            </div>
        </li>
        @foreach ($roles as $role)
            @include('roles._role', ['isOpen' => $open === $role->id])
        @endforeach
    </ul>
@endsection
