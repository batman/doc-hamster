{{-- Mein Konto → Zugangsschlüssel (D-040): the person's own keys for the interface (api/v1) – for a program or an
     assistant. Added with the Aufklapp-Plus row (name + Umschalter "Nur lesen | Lesen + Verknüpfen"); the key shows
     once in an open dialog (Kopierfelder), the list shows name, scope, added, last used and its first characters;
     remove as red row action with a confirm dialog. Making and removing need a fresh confirmation (point 212). --}}
@php
    $adding = $errors->has('name') || $errors->has('scope');
@endphp
@if ($freshKey !== null)
    <div class="dialog-backdrop" data-dialog="access-key-shown">
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="access-key-shown-title">
            <h2 id="access-key-shown-title">{{ __('account.keys.shown_title', ['name' => $freshKey['name']]) }}</h2>
            <p class="card-text"><strong>{{ __('account.keys.shown_once') }}</strong> {{ __('account.keys.shown_text') }}</p>
            @include('settings._device-copy', ['id' => 'access-key-url', 'label' => __('account.keys.url'), 'value' => $apiUrl])
            @include('settings._device-copy', ['id' => 'access-key-value', 'label' => __('account.keys.key'), 'value' => $freshKey['key']])
        </div>
    </div>
@endif

<section class="card">
    <h2 class="card-head"><x-icon name="plug" />{{ __('account.keys.title') }}</h2>
    <p class="card-note">{{ __('account.keys.note') }}</p>
    @unless ($doorOpen)
        <div class="notice">{{ __('api.closed') }}</div>
    @else
    <form method="post" action="{{ route('access-keys.store') }}" class="expand-plus is-row @if ($adding) is-open is-filled @endif" data-expand-plus data-confirm-sudo>
        @csrf
        <button type="button" class="list-entry expand-plus-open"><x-icon name="plus" /><span>{{ __('account.keys.add') }}</span></button>
        <div class="pick-row is-static expand-plus-row">
            <div class="field-addon expand-plus-field">
                <input class="input-small" name="name" maxlength="100" value="{{ old('name') }}" placeholder="{{ __('account.keys.name_placeholder') }}" aria-label="{{ __('account.keys.name') }}" autocomplete="off">
                <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="{{ __('account.keys.create') }}" data-tooltip="{{ __('account.keys.create') }}" @unless ($adding) hidden @endunless><x-icon name="check" /></button></span>
            </div>
            @include('documents._search-choice', ['name' => 'scope', 'value' => old('scope', 'read'), 'options' => __('account.keys.scopes'), 'icons' => ['read' => 'eye', 'link' => 'link'], 'toggle' => false, 'label' => __('account.keys.scope')])
        </div>
        @error('name')<p class="error" role="alert">{{ $message }}</p>@enderror
    </form>
    @endunless
    <ul class="pick-list">
        @forelse ($accessKeys as $access)
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon name="plug" /></span>
                <span>
                    <strong>{{ $access->name }}</strong>
                    {{-- An assistant connected by clicks (D-040) has a changing token: no "beginnt mit". --}}
                    <small>{{ $access->isConnection() ? __('assistant.connected_at', ['date' => $access->created_at->format('d.m.Y')]) : __('account.keys.created_at', ['date' => $access->created_at->format('d.m.Y')]) }} · {{ __('account.keys.last_used', ['date' => $access->last_used_at?->format('d.m.Y H:i') ?? __('account.keys.never')]) }}@unless ($access->isConnection()) · {{ __('account.keys.prefix', ['prefix' => $access->key_prefix]) }}@endunless</small>
                </span>
                <span class="pill">{{ __('account.keys.scopes.'.$access->scope) }}</span>
                <span class="row-actions">
                    <button type="button" class="control-button control-danger" data-dialog-open="delete-access-key-{{ $access->id }}" aria-label="{{ __('account.keys.delete') }}: {{ $access->name }}" data-tooltip="{{ __('account.keys.delete') }}"><x-icon name="trash" /></button>
                </span>
            </li>
        @empty
            <x-empty tag="li" icon="plug" :text="__('account.keys.empty')" />
        @endforelse
    </ul>
</section>

@foreach ($accessKeys as $access)
    <div class="dialog-backdrop" data-dialog="delete-access-key-{{ $access->id }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-access-key-{{ $access->id }}-title">
            <h2 id="delete-access-key-{{ $access->id }}-title">{{ __('account.keys.delete_title', ['name' => $access->name]) }}</h2>
            <p>{{ __('account.keys.delete_text') }}</p>
            <div class="dialog-actions">
                <form method="post" action="{{ route('access-keys.destroy', $access->id) }}" data-confirm-sudo>
                    @csrf
                    <button type="submit" class="button-danger"><x-icon name="trash" />{{ __('account.keys.delete') }}</button>
                </form>
            </div>
        </div>
    </div>
@endforeach
