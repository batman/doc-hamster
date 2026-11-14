{{-- Einstellungen → Geschwindigkeit (point 164.7): measured server times per page (median, slowest 5 %), the speed
     checks with tips (164.6), self tests from this browser (modules/speed.js) and the options. --}}
@extends('layouts.settings')

@section('title', __('speed.title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif

    <section class="card" data-speed-tests
             data-ping="{{ route('ping') }}" data-db="{{ route('speed.db') }}" data-download="{{ route('speed.download') }}" data-window="{{ route('documents.index') }}"
             data-running="{{ __('speed.running') }}" data-failed="{{ __('speed.failed') }}"
             @foreach (__('speed.result') as $key => $text) data-result-{{ $key }}="{{ $text }}" @endforeach>
        <h2 class="card-head"><x-icon name="player-play" />{{ __('speed.tests') }}
            <form class="card-action" data-speed-run><button type="submit" class="button-small"><x-icon name="player-play" />{{ __('speed.run') }}</button></form>
        </h2>
        <p class="card-note">{{ __('speed.tests_note') }}</p>
        <ul class="pick-list">
            @foreach (['server', 'db', 'roundtrip', 'download', 'window'] as $key)
                <li class="pick-row is-static" data-speed-test="{{ $key }}">
                    <span class="row-icon"><x-icon name="gauge" /></span>
                    <span><strong>{{ __('speed.test.'.$key.'.title') }}</strong><small>{{ __('speed.test.'.$key.'.text') }}</small></span>
                    <span class="pill" data-speed-result hidden></span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="list-details" />{{ __('speed.measured') }}
            @if ($rows !== [])
                <form method="post" action="{{ route('speed.reset') }}" class="card-action">
                    @csrf
                    <button type="submit" class="button-small button-quiet"><x-icon name="trash" />{{ __('speed.reset') }}</button>
                </form>
            @endif
        </h2>
        <p class="card-note">{{ __('speed.measured_note') }}</p>
        <ul class="pick-list">
            @forelse ($rows as $row)
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon :name="$row['window'] ? 'window-maximise' : 'file-text'" /></span>
                    <span><strong>{{ $row['title'] }}@if ($row['window']) · {{ __('speed.window') }}@endif</strong>
                        <small>{{ __('speed.row', ['median' => $row['median'], 'p95' => $row['p95'], 'count' => $row['count'], 'queries' => $row['queries'], 'db' => $row['db']]) }}</small></span>
                    <span class="pill {{ $row['median'] <= 150 ? 'pill-ok' : 'pill-warning' }}">{{ $row['median'] }} ms</span>
                </li>
            @empty
                <li class="list-empty">{{ __('speed.none') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="circle-check" />{{ __('speed.checks') }}
            @if ($uncached)
                <form method="post" action="{{ route('speed.optimize') }}" class="card-action">
                    @csrf
                    <button type="submit" class="button-small"><x-icon name="refresh" />{{ __('speed.optimize') }}</button>
                </form>
            @endif
        </h2>
        <ul class="pick-list">
            @foreach ($checks as $check)
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon :name="$check->status === \App\Infrastructure\Environment\CheckStatus::Ok ? 'circle-check' : 'alert-triangle'" /></span>
                    <span><strong>{{ __('doctor.checks.'.$check->key) }}</strong><small>{{ __('doctor.details.'.$check->detail, $check->params) }}</small></span>
                    <span class="pill {{ $check->status === \App\Infrastructure\Environment\CheckStatus::Ok ? 'pill-ok' : 'pill-warning' }}">{{ __('doctor.status.'.$check->status->value) }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="settings" />{{ __('speed.options') }}</h2>
        @foreach (['prefetch' => \App\Domain\Org\SpeedOptions::PREFETCH, 'thumbs' => array_reverse(\App\Domain\Org\SpeedOptions::THUMBS)] as $name => $choices)
            <form method="post" action="{{ route('speed.options') }}" data-autosubmit>
                @csrf
                <fieldset class="pick-list">
                    <legend>{{ __('speed.'.$name) }}</legend>
                    @foreach ($choices as $choice)
                        <label class="pick-row">
                            <input type="radio" class="visually-hidden" name="{{ $name }}" value="{{ $choice }}" @checked($options[$name] === $choice)>
                            <span><strong>{{ __('speed.'.$name.'_options.'.$choice.'.title') }}</strong><small>{{ __('speed.'.$name.'_options.'.$choice.'.text') }}</small></span>
                            <span class="pick-mark"><x-icon name="check" /></span>
                        </label>
                    @endforeach
                </fieldset>
                <noscript><button type="submit" class="button-small">{{ __('speed.saved') }}</button></noscript>
            </form>
        @endforeach
    </section>
@endsection
