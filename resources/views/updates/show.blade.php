{{-- Update page (Go 2026-11-07): three cards; the two lists fill by Ajax into their own card; the password only in a dialog. --}}
@extends('layouts.settings')

@section('title', __('updates.title'))

@section('page')
    @php
        $status = $updated ? __('updates.done', ['version' => $current]) : session('status');
        $releases = $available ? [['version' => $available, 'main' => false]] : ($branchUpdates !== null ? [] : null);
    @endphp

    <h1>{{ __('updates.title') }}</h1>

    @if ($status)
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ $status }}</span></div>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->has('password') ? __('exports.password_wrong') : $errors->first() }}</p>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="device-desktop" />{{ __('updates.installed') }}</h2>
        @if ($previewInfo)
            <ul class="pick-list">
                <li class="pick-row is-static">
                    <x-icon name="git-pull-request" />
                    <span>
                        <span class="tag-outline">{{ __('updates.preview_branch_label') }}</span>
                        <strong>#{{ $previewInfo['number'] }}@if ($previewInfo['branch'] !== '') · {{ $previewInfo['branch'] }}@endif</strong>
                        <small>{{ $previewInfo['title'] !== '' ? $previewInfo['title'] : __('updates.preview_state', ['sha' => $previewInfo['sha']]) }}</small>
                    </span>
                </li>
                {{-- The way back to Main (A95): always here while a branch is installed. --}}
                <li class="pick-row is-static">
                    <x-icon name="cloud-download" />
                    <span><strong>{{ __('updates.main_tile') }}</strong><small>{{ __('updates.main_tile_hint') }}</small></span>
                    @if ($supported)
                        <button type="button" class="button-small" data-dialog-open="update-main" data-dialog-title="{{ __('updates.main_install') }}">{{ __('updates.install_short') }}</button>
                    @endif
                </li>
            </ul>
        @else
            <p class="card-text"><strong>{{ __('updates.version_row', ['version' => $current]) }}</strong></p>
        @endif
    </section>

    <section class="card">
        <h2 class="card-head">
            <x-icon name="cloud-download" />{{ __('updates.updates_heading') }}
            <form method="post" action="{{ route('updates.check') }}" class="card-action" data-ajax="#release-list">
                @csrf
                <button type="submit" class="icon-button" aria-label="{{ __('updates.check') }}" data-tooltip="{{ __('updates.check') }}"><x-icon name="refresh" /></button>
            </form>
        </h2>
        <p class="card-text">{{ __('updates.privacy') }}</p>
        <div id="release-list">@include('updates._releases', ['releases' => $releases, 'branchUpdates' => $branchUpdates ?? [], 'branchGone' => $branchGone])</div>
        @unless ($supported)
            <p class="card-note">{{ __('updates.manual') }}</p>
        @endunless
    </section>

    @can('system.update_preview')
        <section class="card">
            <h2 class="card-head">
                <x-icon name="git-branch" />{{ __('updates.preview_heading') }}
                <form method="post" action="{{ route('updates.previews') }}" class="card-action" data-ajax="#preview-list">
                    @csrf
                    <button type="submit" class="icon-button" aria-label="{{ __('updates.preview_check') }}" data-tooltip="{{ __('updates.preview_check') }}"><x-icon name="refresh" /></button>
                </form>
            </h2>
            <p class="card-text">{{ __('updates.preview_explain') }}</p>
            <div id="preview-list">@include('updates._previews', ['branches' => $previewBranches])</div>
        </section>
    @endcan

    @if ($supported)
        @can('system.update_preview')
            <div class="dialog-backdrop" data-dialog="update-preview" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="update-preview-title">
                    <form method="post" action="{{ route('updates.install-preview') }}" data-update-install>
                        @csrf
                        <input type="hidden" name="confirm_preview" value="1">
                        <input type="hidden" name="preview_pr" value="" data-preview-pr-field>
                        <h2 id="update-preview-title" data-dialog-heading>{{ __('updates.preview_install') }}</h2>
                        <p>{{ __('updates.preview_warning') }}</p>
                        <p>{{ __('updates.password_hint') }}</p>
                        <label for="preview-password" class="visually-hidden">{{ __('exports.password') }}</label>
                        <input id="preview-password" type="password" name="password" autocomplete="current-password" placeholder="{{ __('exports.password') }}" required>
                        <div class="dialog-actions">
                            <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                            <button type="submit">{{ __('updates.install_short') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
        @foreach (['update-release' => route('updates.install'), 'update-main' => route('updates.install-main')] as $key => $action)
            <div class="dialog-backdrop" data-dialog="{{ $key }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $key }}-title">
                    <form method="post" action="{{ $action }}" data-update-install>
                        @csrf
                        <h2 id="{{ $key }}-title" data-dialog-heading>{{ __('updates.install') }}</h2>
                        <p>{{ __('updates.password_hint') }}</p>
                        <label for="{{ $key }}-password" class="visually-hidden">{{ __('exports.password') }}</label>
                        <input id="{{ $key }}-password" type="password" name="password" autocomplete="current-password" placeholder="{{ __('exports.password') }}" required>
                        <div class="dialog-actions">
                            <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                            <button type="submit">{{ __('updates.install_short') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
        {{-- The install runs here instead of on install.php's own page: not closable while running (no data-dialog). --}}
        <div class="dialog-backdrop" data-update-progress hidden
             data-title="{{ __('updates.progress_title') }}"
             data-steps="{{ json_encode(__('updates.progress_steps')) }}"
             data-failed="{{ __('updates.progress_failed') }}"
             data-timeout="{{ __('updates.progress_timeout') }}">
            <div class="dialog update-progress" role="dialog" aria-modal="true" aria-labelledby="update-progress-title">
                <h2 id="update-progress-title" data-progress-title></h2>
                <progress max="100" value="0" data-progress-bar></progress>
                <p role="status" data-progress-step></p>
                <p class="error" role="alert" data-progress-error hidden></p>
                <div class="dialog-actions" data-progress-actions hidden>
                    <button type="button" data-progress-close>{{ __('updates.progress_close') }}</button>
                </div>
            </div>
        </div>
    @endif
@endsection
