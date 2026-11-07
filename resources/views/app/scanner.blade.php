{{-- Drucker & Scanner in the phone app (A22, A30, A31): what is connected, and how to connect.
     DocHamster on the internet: the Scan Station first, step by step. In a home network: search too. --}}
@extends('layouts.phone')

@section('title', __('app.scanner.title'))

@section('head')
    <a class="phone-head-back" href="{{ route('app.documents') }}" aria-label="{{ __('app.docs.back') }}"><x-icon name="chevron-left" /></a>
    <h1>{{ __('app.scanner.title') }}</h1>
@endsection

@section('content')
    {{-- What is connected now. --}}
    @if ($direct || $stations->isNotEmpty())
        <ul class="phone-list">
            @if ($direct)
                <li class="phone-row"><span class="phone-row-main"><x-icon name="printer" /><span>{{ $direct }}<small class="check-ok">{{ __('app.scanner.connected') }}</small></span></span></li>
            @endif
            @foreach ($stations as $station)
                <li class="phone-row">
                    <span class="phone-row-main"><x-icon name="printer" />
                        <span>{{ $station->printer_name ?? __('scan.station_no_printer') }}
                            <small class="@if ($station->isOnline()) check-ok @else muted @endif">{{ $station->name }} · {{ $station->isOnline() ? __('scan.station_online') : __('scan.station_offline_state') }}</small>
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
        <a class="phone-action phone-wide" href="{{ route('app.scan') }}"><x-icon name="scan" />{{ __('app.scan.title') }}</a>
    @endif

    @unless ($admin)
        @if (! $direct && $stations->isEmpty())
            <p class="notice">{{ __('app.scanner.ask_admin') }}</p>
        @endif
    @else
        @if ($code)
            {{-- The Scan Station's one-time code and the steps, shown once. --}}
            <div class="notice phone-steps" role="status">
                <p><strong>{{ __('app.scanner.steps_title') }}</strong></p>
                <ol>
                    <li>{{ __('app.scanner.step_computer') }}</li>
                    <li>{{ __('app.scanner.step_download') }} <a href="{{ route('scanner.station.download') }}">dochamster-station.php</a></li>
                    <li>{{ __('app.scanner.step_run') }}
                        <pre><code>docker run -d --name dochamster-station --restart unless-stopped --network host \
  -e DOCHAMSTER_URL={{ url('/') }} \
  -e PAIRING_CODE={{ $code }} \
  -v dochamster-station:/data -v "$PWD":/app:ro -w /app \
  php:8.4-cli-alpine php dochamster-station.php</code></pre>
                    </li>
                    <li>{{ __('app.scanner.step_done') }}</li>
                </ol>
                <p class="muted">{{ __('app.scanner.code_valid', ['code' => $code]) }}</p>
            </div>
        @elseif (! $searchable && $stations->isEmpty())
            <h2>{{ __('app.scanner.station_title') }}</h2>
            <p>{{ __('app.scanner.station_text') }}</p>
            <form method="post" action="{{ route('scanner.station.create') }}">
                @csrf
                <input type="hidden" name="app_back" value="{{ $back }}">
                <button type="submit" class="phone-action phone-wide"><x-icon name="plug" />{{ __('app.scanner.station_start') }}</button>
            </form>
        @endif

        @if ($searchable)
            <p class="muted">{{ __('app.scanner.intro') }}</p>
            <form method="post" action="{{ route('scanner.search') }}" data-busy="{{ __('app.scanner.searching') }}">
                @csrf
                <input type="hidden" name="app_back" value="{{ $back }}">
                <button type="submit" class="phone-action phone-wide"><x-icon name="search" />{{ __('app.scanner.search') }}</button>
            </form>

            @if (is_array($found))
                @if ($found === [])
                    <p class="muted">{{ __('app.scanner.none_found') }}</p>
                @else
                    <ul class="phone-list">
                        @foreach ($found as $device)
                            <li class="phone-row">
                                <form class="phone-row-main" method="post" action="{{ route('scanner.save') }}">
                                    @csrf
                                    <input type="hidden" name="app_back" value="{{ route('app.scanner', absolute: false) }}">
                                    <input type="hidden" name="url" value="{{ $device['url'] }}">
                                    <x-icon name="printer" /><span>{{ $device['model'] }}<small class="muted">{{ $device['url'] }}</small></span>
                                    <button type="submit">{{ __('app.scanner.use') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif

            <details class="phone-new">
                <summary>{{ __('app.scanner.manual') }}</summary>
                <form method="post" action="{{ route('scanner.save') }}">
                    @csrf
                    <input type="hidden" name="app_back" value="{{ route('app.scanner', absolute: false) }}">
                    <label for="scanner-url">{{ __('scan.address') }}</label>
                    <input id="scanner-url" name="url" maxlength="200" inputmode="url" placeholder="192.0.2.10" required>
                    <button type="submit">{{ __('app.scanner.use') }}</button>
                </form>
            </details>
        @endif

        @if (! $code && ($searchable || $stations->isNotEmpty()))
            <details class="phone-new">
                <summary><x-icon name="plus" />{{ $stations->isEmpty() ? __('app.scanner.station_title') : __('app.scanner.station_more') }}</summary>
                <p class="muted">{{ __('app.scanner.station_text') }}</p>
                <form method="post" action="{{ route('scanner.station.create') }}">
                    @csrf
                    <input type="hidden" name="app_back" value="{{ $back }}">
                    <button type="submit">{{ __('app.scanner.station_start') }}</button>
                </form>
            </details>
        @endif
    @endunless
@endsection
