{{-- One role of the Rollen page (points 215, 216, 223): head (name, "N Personen · rights", pills) and the body that opens. --}}
@php
    $restriction = $role->restriction();
    $short = collect($role->rights)->map(fn (string $r): string => (string) __('roles.short.'.$r))->implode(', ');
    $ticked = $restriction->folderIds ?? [];
    $openPath = [];
    foreach ($ticked as $tick) {
        $at = $byId->get($tick)['folder']->parent_id ?? null;
        while ($at !== null && ! in_array($at, $openPath, true)) {
            $openPath[] = $at;
            $at = $byId->get($at)['folder']->parent_id ?? null;
        }
    }
    $pauseText = trans_choice('roles.pause_text', $role->memberships_count, ['count' => $role->memberships_count]);
    $restrictionSummary = $restriction->isEmpty() ? __('roles.restrictions_none') : implode(' · ', $restriction->pills());
@endphp
<li @class(['accordion-row', 'is-open' => $isOpen]) id="role-{{ $role->id }}">
    <div class="accordion-head">
        <button type="button" class="list-entry accordion-toggle" data-accordion-toggle aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="role-body-{{ $role->id }}">
            <span class="row-icon"><x-icon name="user-shield" /></span>
            <span><strong data-row-name>{{ $role->name }}</strong>
                <small>{{ trans_choice('roles.people', $role->memberships_count, ['count' => $role->memberships_count]) }} · {{ $short !== '' ? $short : __('roles.no_rights') }}</small></span>
            <span class="accordion-pills">
                <span class="pill" data-paused-pill @unless ($role->isPaused()) hidden @endunless>{{ __('roles.paused_pill') }}</span>
                @if ($role->isPreinstalled())<span class="pill">{{ __('roles.preinstalled') }}</span>@endif
                <span class="accordion-pills" data-restriction-pills>@foreach ($restriction->pills() as $pill)<span class="pill">{{ $pill }}</span>@endforeach</span>
            </span>
        </button>
        {{-- Row actions (PO 2026-11-16): a row never has a ⋯ menu – Löschen is the red trash. --}}
        <span class="row-actions">
            <button type="button" class="control-button control-danger" data-dialog-open="role-delete-{{ $role->id }}" aria-label="{{ __('roles.delete') }}: {{ $role->name }}" data-tooltip="{{ __('roles.delete') }}"><x-icon name="trash" /></button>
        </span>
    </div>
    <div class="accordion-body" id="role-body-{{ $role->id }}" @unless ($isOpen) hidden @endunless>
        <form method="post" action="{{ route('roles.update', $role->id) }}" data-autosave>
            @csrf
            <div class="field">
                <label for="role-name-{{ $role->id }}">{{ __('roles.name') }}</label>
                <div class="field-addon">
                    <input id="role-name-{{ $role->id }}" name="name" value="{{ $role->name }}" maxlength="64" required>
                    <span class="addon-end"><button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('roles.save') }}" data-tooltip="{{ __('roles.save') }}" hidden><x-icon name="check" /></button></span>
                </div>
            </div>
            @foreach ($groups as $group => $rights)
                <fieldset>
                    <legend>{{ __('roles.groups.'.$group) }}</legend>
                    @foreach ($rights as $right)
                        <label><input type="checkbox" name="rights[]" value="{{ $right->value }}" @checked($role->has($right))> {{ __('roles.rights.'.$right->value) }}</label>
                    @endforeach
                </fieldset>
            @endforeach
            <noscript><button type="submit" class="button-small">{{ __('roles.saved') }}</button></noscript>
        </form>

        <x-fold-card :fold="'roles.restrict.'.$role->id" :title="__('roles.restrictions')" icon="filter" compact :remember="false" :folded="$restriction->isEmpty()" :summary="$restrictionSummary">
            <form method="post" action="{{ route('roles.restrict', $role->id) }}" data-autosave>
                @csrf
                <p class="card-note">{{ __('roles.restrictions_hint') }}</p>
                <fieldset>
                    <legend>{{ __('roles.period') }}</legend>
                    <div class="search-row">
                        <div class="field">
                            <label for="role-from-{{ $role->id }}">{{ __('roles.from') }}</label>
                            <input id="role-from-{{ $role->id }}" type="date" name="period_from" value="{{ $restriction->from?->toDateString() }}">
                        </div>
                        <div class="field">
                            <label for="role-to-{{ $role->id }}">{{ __('roles.to') }}</label>
                            <input id="role-to-{{ $role->id }}" type="date" name="period_to" value="{{ $restriction->to?->toDateString() }}">
                        </div>
                    </div>
                    <p class="field-hint">{{ __('roles.period_hint') }}</p>
                </fieldset>
                <fieldset>
                    <legend>{{ __('roles.accounts') }}</legend>
                    @forelse ($accounts as $account)
                        <label><input type="checkbox" name="accounts[]" value="{{ $account->id }}" @checked(in_array($account->id, $restriction->accountIds ?? [], true))> {{ $account->name }}</label>
                    @empty
                        <p class="card-note">{{ __('roles.no_accounts') }}</p>
                    @endforelse
                </fieldset>
                <fieldset>
                    <legend>{{ __('roles.folders') }}</legend>
                    <div class="folder-menu" data-expand="{{ __('inbox.expand') }}" data-collapse="{{ __('inbox.collapse') }}">
                        @include('roles._folder-pick', ['parent' => '', 'open' => true])
                    </div>
                    <p class="field-hint">{{ __('roles.folders_hint') }}</p>
                </fieldset>
                <noscript><button type="submit" class="button-small">{{ __('roles.saved') }}</button></noscript>
            </form>
        </x-fold-card>

        <form method="post" action="{{ route('roles.pause', $role->id) }}" data-autosave>
            @csrf
            {{-- PO 2026-11-16: an Umschalter instead of the toggle switch; "Pausiert" asks first. --}}
            <fieldset>
                <legend>{{ __('roles.status') }}</legend>
                <span class="button-choice" data-choice>
                    <input type="hidden" name="paused" value="{{ $role->isPaused() ? '1' : '0' }}">
                    <span class="switcher" role="group" aria-label="{{ __('roles.status') }}">
                        <button type="button" data-value="0" aria-pressed="{{ $role->isPaused() ? 'false' : 'true' }}"><x-icon name="player-play" />{{ __('roles.active') }}</button>
                        <button type="button" data-value="1" aria-pressed="{{ $role->isPaused() ? 'true' : 'false' }}"
                            data-confirm-title="{{ __('roles.pause_title') }}" data-confirm-text="{{ $pauseText }}" data-confirm-ok="{{ __('roles.pause_ok') }}"><x-icon name="player-pause" />{{ __('roles.paused') }}</button>
                    </span>
                </span>
            </fieldset>
            <p class="field-hint">{{ __('roles.paused_hint') }}</p>
        </form>
    </div>

    <div class="dialog-backdrop" data-dialog="role-delete-{{ $role->id }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="role-delete-{{ $role->id }}-title">
            @if ($role->isPreinstalled() || $role->memberships_count > 0)
                <h2 id="role-delete-{{ $role->id }}-title">{{ __('roles.cannot_delete') }}</h2>
                <p>{{ $role->isPreinstalled() ? __('roles.cannot_preinstalled') : trans_choice('roles.cannot_in_use', $role->memberships_count, ['count' => $role->memberships_count]) }}</p>
            @else
                <h2 id="role-delete-{{ $role->id }}-title">{{ __('roles.delete_title') }}</h2>
                <p>{{ __('roles.delete_text', ['name' => $role->name]) }}</p>
                <form method="post" action="{{ route('roles.destroy', $role->id) }}" class="dialog-actions" data-confirm-sudo>
                    @csrf
                    <button type="submit" class="button-danger"><x-icon name="trash" />{{ __('roles.delete') }}</button>
                </form>
            @endif
        </div>
    </div>
</li>
