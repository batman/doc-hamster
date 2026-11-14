{{-- Einstellungen → Backup (point 206, D-023): choice (Alles / Zeitraum, files, parts), the expected size (asked again on
     every change, modules/backup.js), optional ZIP password; start and download need a fresh confirmation
     ("Bitte bestätigen", point 212). Below: this person's background backups with progress and download. --}}
@extends('layouts.settings')

@section('title', __('backup.title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="archive" />{{ __('backup.title') }}</h2>
        <p class="card-text">{{ __('backup.intro') }}</p>
        @if ($last !== null)
            <p class="card-note">{{ __('backup.last') }}: {{ $last->format('d.m.Y H:i') }}</p>
        @endif

        <form method="post" action="{{ route('backup.store') }}" id="backup-form" data-backup data-estimate="{{ route('backup.estimate') }}" data-confirm-sudo>
            @csrf
            <fieldset>
                <legend>{{ __('backup.range') }}</legend>
                @include('documents._search-choice', ['name' => 'range', 'value' => old('range', ''), 'options' => ['' => __('backup.range_all'), 'period' => __('backup.range_period')], 'toggle' => false, 'label' => __('backup.range')])
            </fieldset>
            <div class="search-row" data-backup-period @if (old('range', '') !== 'period') hidden @endif>
                <div class="field">
                    <label for="backup-from">{{ __('backup.from') }}</label>
                    <input id="backup-from" type="date" name="from" value="{{ old('from') }}">
                </div>
                <div class="field">
                    <label for="backup-to">{{ __('backup.to') }}</label>
                    <input id="backup-to" type="date" name="to" value="{{ old('to') }}">
                </div>
            </div>
            <p class="card-note" data-backup-period @if (old('range', '') !== 'period') hidden @endif>{{ __('backup.period_note') }}</p>

            <fieldset>
                <legend>{{ __('backup.files') }}</legend>
                @include('documents._search-choice', ['name' => 'derived', 'value' => old('derived', ''), 'options' => ['' => __('backup.files_originals'), '1' => __('backup.files_derived')], 'toggle' => false, 'label' => __('backup.files')])
                <p class="card-note">{{ __('backup.files_note') }}</p>
            </fieldset>

            <fieldset>
                <legend>{{ __('backup.parts') }}</legend>
                @foreach (\App\Domain\Backup\BackupOptions::SECTIONS as $part)
                    <label class="check"><input type="checkbox" name="parts[]" value="{{ $part }}" @checked(in_array($part, old('parts', \App\Domain\Backup\BackupOptions::SECTIONS), true))>
                        <span><strong>{{ __('backup.part.'.$part.'.title') }}</strong> <small class="muted">{{ __('backup.part.'.$part.'.text') }}</small></span></label>
                @endforeach
            </fieldset>

            <p><strong>{{ __('backup.size') }}:</strong> <span data-backup-size>{{ __('backup.size_value', ['size' => \App\Http\Controllers\BackupController::human($estimate['bytes'])]) }}</span></p>
            @php $background = $estimate['bytes'] > $limit; @endphp
            <p class="card-note" data-backup-mode>{{ $background ? __('backup.size_background', ['limit' => \App\Http\Controllers\BackupController::human($limit)]) : __('backup.size_now') }}</p>

            <fieldset>
                <legend>{{ __('backup.protect') }}</legend>
                <div class="field @error('zip_password') has-error @enderror">
                    <label for="backup-zip-password">{{ __('backup.password') }}</label>
                    <input id="backup-zip-password" type="password" name="zip_password" minlength="12" maxlength="200" autocomplete="new-password" data-backup-zip-password>
                    <p class="field-hint">{{ __('backup.password_hint') }}</p>
                </div>
                <div class="field">
                    <label for="backup-zip-password-confirmation">{{ __('backup.password_confirmation') }}</label>
                    <input id="backup-zip-password-confirmation" type="password" name="zip_password_confirmation" minlength="12" maxlength="200" autocomplete="new-password">
                </div>
                <p class="card-note" data-backup-warning><x-icon name="alert-triangle" /> {{ __('backup.password_warning') }}</p>
            </fieldset>

            <button type="submit"><x-icon name="download" />{{ __('backup.start') }}</button>
        </form>
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="list-details" />{{ __('backup.list') }}</h2>
        <p class="card-note">{{ __('backup.list_note', ['hours' => \App\Domain\Backup\Backup::keepHours()]) }}</p>
        <ul class="pick-list">
            @forelse ($backups as $backup)
                <li class="pick-row is-static" @if ($backup->isRunning()) data-backup-running @endif>
                    <span class="row-icon"><x-icon name="archive" /></span>
                    <span><strong>{{ $backup->created_at->format('d.m.Y H:i') }}</strong>
                        <small>{{ \App\Http\Controllers\BackupController::human((int) ($backup->size ?? $backup->estimate)) }}</small></span>
                    @php $state = $backup->status === \App\Domain\Backup\Backup::DONE && ! $backup->isReady() ? \App\Domain\Backup\Backup::EXPIRED : $backup->status; @endphp
                    <span class="pill {{ $state === 'done' ? 'pill-ok' : ($state === 'failed' ? 'pill-error' : '') }}">{{ __('backup.status.'.$state, ['progress' => $backup->progress]) }}</span>
                    @if ($backup->isReady())
                        <form method="post" action="{{ route('backup.download', $backup->id) }}" class="row-actions" data-confirm-sudo>
                            @csrf
                            <button type="submit" class="icon-button" aria-label="{{ __('backup.download') }}" data-tooltip="{{ __('backup.download') }}"><x-icon name="download" />@if ($backup->downloaded_at === null)<span class="badge badge-alert">1</span>@endif</button>
                        </form>
                    @endif
                </li>
            @empty
                <x-empty tag="li" icon="archive" :text="__('backup.none')" />
            @endforelse
        </ul>
    </section>
@endsection
