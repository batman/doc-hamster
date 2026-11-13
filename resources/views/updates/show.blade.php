{{-- Update page (task 109, variant C): one card "Versionen" with one "Update" button in its head; the list of all
     versions fills by Ajax into the card; updating and switching ask in a dialog, the password only there. --}}
@extends('layouts.settings')

@section('title', __('updates.title'))

@section('page')
    @php($status = $updated ? __('updates.done', ['version' => $current]) : session('status'))

    <h1>{{ __('updates.title') }}</h1>

    @if ($status)
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ $status }}</span></div>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->has('password') ? __('exports.password_wrong') : $errors->first() }}</p>
    @endif

    <section class="card">
        <h2 class="card-head">
            <x-icon name="git-branch" />{{ __('updates.versions') }}
            <form method="post" action="{{ route('updates.check') }}" class="card-action" data-ajax="#version-list">
                @csrf
                <button type="submit" class="button-small"><x-icon name="refresh" />{{ __('updates.refresh') }}</button>
            </form>
        </h2>
        <p class="card-text">{{ __('updates.privacy') }}</p>
        <div id="version-list">@include('updates._versions', ['rows' => $rows, 'failed' => false])</div>
        @unless ($supported)
            <p class="card-note">{{ __('updates.manual') }}</p>
        @endunless
    </section>

    @if ($supported)
        {{-- Main: "Aktualisieren" (newest release, only while Main runs) and "Wechseln" back from a preview. --}}
        @foreach (['update-main' => [route('updates.install'), 'updates.update_text'], 'switch-main' => [route('updates.install-main'), 'updates.switch_text']] as $key => [$action, $text])
            <div class="dialog-backdrop" data-dialog="{{ $key }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $key }}-title">
                    <form method="post" action="{{ $action }}" data-update-install>
                        @csrf
                        <h2 id="{{ $key }}-title" data-dialog-heading>{{ __($key === 'switch-main' ? 'updates.switch_main_title' : 'updates.update_short') }}</h2>
                        <p>{{ __($text) }}</p>
                        <p>{{ __('updates.password_hint') }}</p>
                        <label for="{{ $key }}-password" class="visually-hidden">{{ __('exports.password') }}</label>
                        <input id="{{ $key }}-password" type="password" name="password" autocomplete="current-password" placeholder="{{ __('exports.password') }}" required>
                        <div class="dialog-actions">
                            <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                            <button type="submit">{{ __($key === 'switch-main' ? 'updates.switch' : 'updates.update_short') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
        @can('system.update_preview')
            {{-- Preview: "Aktualisieren" within the installed branch and "Wechseln" to another branch; the row puts its number in. --}}
            @foreach (['update-preview' => 'updates.update_text', 'switch-preview' => 'updates.switch_text'] as $key => $text)
                <div class="dialog-backdrop" data-dialog="{{ $key }}" hidden>
                    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $key }}-title">
                        <form method="post" action="{{ route('updates.install-preview') }}" data-update-install>
                            @csrf
                            <input type="hidden" name="confirm_preview" value="1">
                            <input type="hidden" name="preview_pr" value="" data-preview-pr-field>
                            <h2 id="{{ $key }}-title" data-dialog-heading>{{ __($key === 'switch-preview' ? 'updates.switch' : 'updates.update_short') }}</h2>
                            <p>{{ __($text) }}</p>
                            <p>{{ __('updates.password_hint') }}</p>
                            <label for="{{ $key }}-password" class="visually-hidden">{{ __('exports.password') }}</label>
                            <input id="{{ $key }}-password" type="password" name="password" autocomplete="current-password" placeholder="{{ __('exports.password') }}" required>
                            <div class="dialog-actions">
                                <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                                <button type="submit">{{ __($key === 'switch-preview' ? 'updates.switch' : 'updates.update_short') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        @endcan
        {{-- The install runs here instead of on install.php's own page: not closable while running (no data-dialog). --}}
        <div class="dialog-backdrop" data-update-progress hidden
             data-title="{{ __('updates.progress_title') }}"
             data-steps="{{ json_encode(__('updates.progress_steps')) }}"
             data-failed="{{ __('updates.progress_failed') }}"
             data-timeout="{{ __('updates.progress_timeout') }}"
             data-unreachable="{{ __('updates.progress_unreachable') }}">
            <div class="dialog update-progress" role="dialog" aria-modal="true" aria-labelledby="update-progress-title">
                <h2 id="update-progress-title" data-progress-title></h2>
                <progress max="100" value="0" data-progress-bar></progress>
                <p role="status" data-progress-step></p>
                <p class="error" role="alert" data-progress-error hidden></p>
                <div class="dialog-actions" data-progress-actions hidden>
                    <button type="button" class="button-quiet" data-progress-close>{{ __('updates.progress_close') }}</button>
                    <button type="button" data-progress-retry hidden>{{ __('updates.progress_retry') }}</button>
                </div>
            </div>
        </div>
    @endif
@endsection
