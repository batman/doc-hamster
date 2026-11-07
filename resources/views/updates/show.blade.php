@extends('layouts.settings')

@section('title', __('updates.title'))

@section('page')
    <h1>{{ __('updates.title') }}</h1>
    <p>{{ __('updates.current', ['version' => $current]) }}</p>

    @if ($updated)
        <p class="check-ok" role="status">{{ __('updates.done', ['version' => $current]) }}</p>
    @endif
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->has('password') ? __('exports.password_wrong') : $errors->first() }}</p>
    @endif

    @if ($available)
        <div class="notice">
            <p><strong>{{ __('updates.available', ['version' => $available]) }}</strong></p>
            <p>{{ __('updates.keeps_data') }}</p>
            @if ($supported)
                <form method="post" action="{{ route('updates.install') }}">
                    @csrf
                    <label for="password">{{ __('exports.password') }}</label>
                    <input id="password" type="password" name="password" autocomplete="current-password" required>
                    <button type="submit">{{ __('updates.install') }}</button>
                </form>
            @else
                <p>{{ __('updates.manual') }}</p>
            @endif
        </div>
    @else
        <p class="muted">{{ __('updates.privacy') }}</p>
        <form method="post" action="{{ route('updates.check') }}">
            @csrf
            <button type="submit">{{ __('updates.check') }}</button>
        </form>
    @endif
    @if ($previewInstalled)
        <div class="notice">
            <p>{{ __('updates.main_recommended') }}</p>
            <form method="post" action="{{ route('updates.install-main') }}">
                @csrf
                <label for="main-password">{{ __('exports.password') }}</label>
                <input id="main-password" type="password" name="password" autocomplete="current-password" required>
                <button type="submit">{{ __('updates.install_main') }}</button>
            </form>
        </div>
    @endif

    @can('system.update_preview')
    <section class="preview-branches">
        <h2>{{ __('updates.preview_heading') }}</h2>
        <p>{{ __('updates.preview_explain') }}</p>
        <form method="post" action="{{ route('updates.previews') }}">
            @csrf
            <button type="submit">{{ __('updates.preview_check') }}</button>
        </form>

        @if (is_array($previewBranches))
            @if ($previewBranches === [])
                <p class="muted">{{ __('updates.preview_none') }}</p>
            @else
                <form method="post" action="{{ route('updates.install-preview') }}">
                    @csrf
                    <fieldset class="preview-list">
                        <legend>{{ __('updates.preview_choose') }}</legend>
                        @foreach ($previewBranches as $branch)
                            <label class="preview-branch">
                                <input type="radio" name="preview_pr" value="{{ $branch['number'] }}" required>
                                <span><strong>PR #{{ $branch['number'] }} · {{ $branch['branch'] }}</strong><br>{{ $branch['title'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    <div class="notice preview-warning">
                        <p>{{ __('updates.preview_warning') }}</p>
                        <label><input type="checkbox" name="confirm_preview" value="1" required> {{ __('updates.preview_confirm') }}</label>
                    </div>
                    <label for="preview-password">{{ __('exports.password') }}</label>
                    <input id="preview-password" type="password" name="password" autocomplete="current-password" required>
                    <button type="submit">{{ __('updates.preview_install') }}</button>
                </form>
            @endif
        @endif
    </section>
    @endcan
@endsection
