{{-- Update page (Go 2026-11-07, A16-A22): three cards, a badge for an installed preview, the password only in a dialog. --}}
@extends('layouts.settings')

@section('title', __('updates.title'))

@section('page')
    @php
        $preview = preg_match('/^preview-(\d+)-([0-9a-f]+)$/', $current, $m) === 1 ? ['number' => $m[1], 'sha' => $m[2]] : null;
        $status = $updated ? __('updates.done', ['version' => $current]) : session('status');
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
        @if ($preview)
            <div class="card-row">
                <span class="badge-pill"><x-icon name="flask" />{{ __('updates.preview_badge', ['number' => $preview['number']]) }}</span>
                <button type="button" class="button-quiet button-small" data-dialog-open="update-main"><x-icon name="arrow-back-up" />{{ __('updates.back_to_main') }}</button>
            </div>
            <p class="card-note">{{ __('updates.preview_state', ['sha' => $preview['sha']]) }}</p>
        @else
            <p class="card-text">{{ __('updates.current', ['version' => $current]) }}</p>
        @endif
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="cloud-download" />{{ __('updates.updates_heading') }}</h2>
        @if ($available)
            <p class="card-text"><strong>{{ __('updates.available', ['version' => $available]) }}</strong> {{ __('updates.keeps_data') }}</p>
            @if ($supported)
                <button type="button" data-dialog-open="update-release">{{ __('updates.install') }}</button>
            @else
                <p class="card-text">{{ __('updates.manual') }}</p>
            @endif
        @else
            <p class="card-text">{{ __('updates.privacy') }}</p>
            <form method="post" action="{{ route('updates.check') }}">
                @csrf
                <button type="submit" class="button-quiet button-small"><x-icon name="refresh" />{{ __('updates.check') }}</button>
            </form>
        @endif
    </section>

    @can('system.update_preview')
        <section class="card">
            <h2 class="card-head"><x-icon name="git-branch" />{{ __('updates.preview_heading') }}</h2>
            <p class="card-text">{{ __('updates.preview_explain') }}</p>
            <form method="post" action="{{ route('updates.previews') }}">
                @csrf
                <button type="submit" class="button-quiet button-small"><x-icon name="refresh" />{{ __('updates.preview_check') }}</button>
            </form>

            @if (is_array($previewBranches))
                @if ($previewBranches === [])
                    <p class="card-note">{{ __('updates.preview_none') }}</p>
                @else
                    <form method="post" action="{{ route('updates.install-preview') }}" data-preview-form>
                        @csrf
                        <input type="hidden" name="confirm_preview" value="1">
                        <fieldset class="pick-list">
                            <legend class="visually-hidden">{{ __('updates.preview_choose') }}</legend>
                            @foreach ($previewBranches as $branch)
                                <label class="pick-row">
                                    <input type="radio" class="visually-hidden" name="preview_pr" value="{{ $branch['number'] }}" required>
                                    <x-icon name="git-pull-request" />
                                    <span><strong>#{{ $branch['number'] }} · {{ $branch['branch'] }}</strong><small>{{ $branch['title'] }}</small></span>
                                    <span class="pick-mark"><x-icon name="check" /></span>
                                </label>
                            @endforeach
                        </fieldset>
                        <p class="card-text">{{ __('updates.preview_warning') }}</p>
                        <button type="button" data-dialog-open="update-preview" data-preview-open data-label="{{ __('updates.preview_install_number', ['number' => '__N__']) }}" disabled>{{ __('updates.preview_install') }}</button>

                        <div class="dialog-backdrop" data-dialog="update-preview" hidden>
                            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="update-preview-title">
                                <h2 id="update-preview-title" data-preview-title>{{ __('updates.preview_install') }}</h2>
                                <p>{{ __('updates.password_hint') }}</p>
                                <label for="preview-password" class="visually-hidden">{{ __('exports.password') }}</label>
                                <input id="preview-password" type="password" name="password" autocomplete="current-password" placeholder="{{ __('exports.password') }}" required>
                                <div class="dialog-actions">
                                    <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                                    <button type="submit">{{ __('updates.install_short') }}</button>
                                </div>
                            </div>
                        </div>
                    </form>
                @endif
            @endif
        </section>
    @endcan

    @foreach (array_filter([
        'update-release' => $available && $supported ? [route('updates.install'), __('updates.available', ['version' => $available])] : null,
        'update-main' => $preview ? [route('updates.install-main'), __('updates.back_to_main')] : null,
    ]) as $key => [$action, $title])
        <div class="dialog-backdrop" data-dialog="{{ $key }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $key }}-title">
                <form method="post" action="{{ $action }}">
                    @csrf
                    <h2 id="{{ $key }}-title">{{ $title }}</h2>
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
@endsection
