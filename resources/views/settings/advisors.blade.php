{{-- Einstellungen → Personen → Steuerberater-Zugang (point 210): the accesses with their scope; create, change, new
     one-time password, end. The one-time password shows once in an open dialog (cache + token, like Geräte).
     A380: "Zuletzt angesehen" per access from the audit trail. --}}
@extends('layouts.settings')

@section('title', __('advisor.title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    @if ($fresh !== null)
        <div class="dialog-backdrop" data-dialog="advisor-password">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="advisor-password-title">
                <h2 id="advisor-password-title">{{ __('advisor.password_title') }}</h2>
                <p class="card-text"><strong>{{ __('advisor.password_once') }}</strong> {{ __('advisor.password_pass_on') }}</p>
                @include('settings._device-copy', ['id' => 'advisor-username', 'label' => __('advisor.username'), 'value' => $fresh['username']])
                @include('settings._device-copy', ['id' => 'advisor-onetime', 'label' => __('advisor.onetime'), 'value' => $fresh['password']])
                <p class="field-hint">{{ __('advisor.onetime_hint', ['url' => route('login')]) }}</p>
                <div class="dialog-actions">
                    <button type="button" data-dialog-close>{{ __('advisor.done') }}</button>
                </div>
            </div>
        </div>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="briefcase" />{{ __('advisor.list') }}
            <span class="card-action">
                <button type="button" class="button-small" data-dialog-open="advisor-add"><x-icon name="plus" />{{ __('advisor.add') }}</button>
            </span>
        </h2>
        <p class="card-text">{{ __('advisor.intro') }}</p>
        <ul class="pick-list">
            @forelse ($accesses as $access)
                @php
                    $period = $access->period_from === null && $access->period_to === null ? __('advisor.period_all')
                        : ($access->period_from?->format('d.m.Y') ?? '…').' – '.($access->period_to?->format('d.m.Y') ?? '…');
                    $accountText = $access->account_ids === null ? __('advisor.all_accounts')
                        : $accounts->whereIn('id', $access->account_ids)->pluck('name')->implode(', ');
                @endphp
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon name="briefcase" /></span>
                    <span>
                        <strong>{{ $access->user->username }}</strong>
                        <small>{{ $period }} · {{ $accountText !== '' ? $accountText : __('advisor.no_accounts') }}@if ($access->expires_at) · {{ __('advisor.until', ['date' => $access->expires_at->format('d.m.Y')]) }}@endif @if ($access->user->email) · {{ $access->user->email }}@endif</small>
                    </span>
                    @if ($access->isExpired())
                        <span class="pill pill-error">{{ __('advisor.expired_pill') }}</span>
                    @elseif ($access->must_change_password)
                        <span class="pill pill-warning">{{ __('advisor.onetime_pill') }}</span>
                    @endif
                    <span class="row-actions">
                        <button type="button" class="control-button" data-dialog-open="advisor-edit-{{ $access->id }}" aria-label="{{ __('advisor.edit') }}: {{ $access->user->username }}" data-tooltip="{{ __('advisor.edit') }}"><x-icon name="pencil" /></button>
                        <button type="button" class="control-button" data-dialog-open="advisor-reset-{{ $access->id }}" aria-label="{{ __('advisor.reset') }}: {{ $access->user->username }}" data-tooltip="{{ __('advisor.reset') }}"><x-icon name="refresh" /></button>
                        <button type="button" class="control-button" data-dialog-open="advisor-revoke-{{ $access->id }}" aria-label="{{ __('advisor.revoke') }}: {{ $access->user->username }}" data-tooltip="{{ __('advisor.revoke') }}"><x-icon name="trash" /></button>
                    </span>
                </li>
            @empty
                <x-empty tag="li" icon="briefcase" :text="__('advisor.none')" />
            @endforelse
        </ul>
    </section>

    @foreach ($accesses as $access)
        @php $views = $recent[$access->id] ?? []; @endphp
        <x-fold-card :fold="'advisors.recent.'.$access->id" :title="__('advisor.recent', ['name' => $access->user->username])" icon="eye" folded :summary="(string) count($views)">
            <ul class="pick-list">
                @forelse ($views as $view)
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon :name="$view['action'] === 'advisor.document_viewed' ? 'eye' : 'download'" /></span>
                        <span><strong>{{ $view['title'] }}</strong><small>{{ __('advisor.actions.'.\Illuminate\Support\Str::after($view['action'], 'advisor.')) }} · {{ $view['at']->format('d.m.Y H:i') }}</small></span>
                    </li>
                @empty
                    <x-empty tag="li" icon="eye" :text="__('advisor.recent_none')" />
                @endforelse
            </ul>
        </x-fold-card>
    @endforeach

    <div class="dialog-backdrop" data-dialog="advisor-add" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="advisor-add-title">
            <h2 id="advisor-add-title">{{ __('advisor.add_title') }}</h2>
            <form method="post" action="{{ route('advisors.store') }}" data-confirm-sudo>
                @csrf
                <div class="field">
                    <label for="advisor-username-new">{{ __('advisor.username') }}</label>
                    <input id="advisor-username-new" name="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9._\-]+" required autocomplete="off" value="{{ old('username') }}">
                    <p class="field-hint">{{ __('advisor.username_hint') }}</p>
                </div>
                @include('settings._advisor-scope', ['access' => null, 'key' => 'new'])
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('advisor.cancel') }}</button>
                    <button type="submit"><x-icon name="plus" />{{ __('advisor.add') }}</button>
                </div>
            </form>
        </div>
    </div>

    @foreach ($accesses as $access)
        <div class="dialog-backdrop" data-dialog="advisor-edit-{{ $access->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="advisor-edit-{{ $access->id }}-title">
                <h2 id="advisor-edit-{{ $access->id }}-title">{{ __('advisor.edit_title', ['name' => $access->user->username]) }}</h2>
                <form method="post" action="{{ route('advisors.update', $access->id) }}" data-confirm-sudo>
                    @csrf
                    @include('settings._advisor-scope', ['access' => $access, 'key' => $access->id])
                    <div class="dialog-actions">
                        <button type="button" class="button-quiet" data-dialog-close>{{ __('advisor.cancel') }}</button>
                        <button type="submit"><x-icon name="check" />{{ __('advisor.save') }}</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="dialog-backdrop" data-dialog="advisor-reset-{{ $access->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="advisor-reset-{{ $access->id }}-title">
                <h2 id="advisor-reset-{{ $access->id }}-title">{{ __('advisor.reset_title') }}</h2>
                <p>{{ __('advisor.reset_text', ['name' => $access->user->username]) }}</p>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('advisor.cancel') }}</button>
                    <form method="post" action="{{ route('advisors.reset', $access->id) }}" data-confirm-sudo>
                        @csrf
                        <button type="submit"><x-icon name="refresh" />{{ __('advisor.reset') }}</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="dialog-backdrop" data-dialog="advisor-revoke-{{ $access->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="advisor-revoke-{{ $access->id }}-title">
                <h2 id="advisor-revoke-{{ $access->id }}-title">{{ __('advisor.revoke_title') }}</h2>
                <p>{{ __('advisor.revoke_text', ['name' => $access->user->username]) }}</p>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('advisor.cancel') }}</button>
                    <form method="post" action="{{ route('advisors.revoke', $access->id) }}" data-confirm-sudo>
                        @csrf
                        <button type="submit" class="button-danger">{{ __('advisor.revoke') }}</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endsection
