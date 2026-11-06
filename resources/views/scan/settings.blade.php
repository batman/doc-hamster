@extends('layouts.settings')

@section('title', __('scan.settings_title'))

@section('page')
    <h1>{{ __('scan.settings_title') }}</h1>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <h2>{{ __('scan.station_title') }}</h2>
    <p>{{ __('scan.station_text') }}</p>

    @if ($code)
        {{-- Shown once: the code is stored only as a hash. --}}
        <div class="notice" role="status">
            <p><strong>{{ __('scan.station_code_title', ['name' => $codeName]) }}</strong></p>
            <p>{{ __('scan.station_code_text') }}</p>
            <p><code>{{ $code }}</code></p>
            <ol>
                <li>{{ __('scan.station_step_download') }} <a href="{{ route('scanner.station.download') }}">dochamster-station.php</a></li>
                <li>{{ __('scan.station_step_run') }}</li>
            </ol>
            <pre><code>docker run -d --name dochamster-station --restart unless-stopped --network host \
  -e DOCHAMSTER_URL={{ url('/') }} \
  -e PAIRING_CODE={{ $code }} \
  -v dochamster-station:/data -v "$PWD":/app:ro -w /app \
  php:8.4-cli-alpine php dochamster-station.php</code></pre>
            <p class="muted">{{ __('scan.station_step_printer') }}</p>
        </div>
    @endif

    @if ($stations->isNotEmpty())
        <ul class="station-list">
            @foreach ($stations as $station)
                <li>
                    <strong>{{ $station->name }}</strong>
                    <span class="muted">{{ $station->printer_name ?? __('scan.station_no_printer') }}</span>
                    <span class="@if ($station->isOnline()) check-ok @else muted @endif">{{ $station->isOnline() ? __('scan.station_online') : __('scan.station_offline_state') }}</span>
                    <form method="post" action="{{ route('scanner.station.revoke', $station->id) }}">
                        @csrf
                        <button type="submit" class="button-danger">{{ __('scan.station_remove') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
    @if ($waiting > 0 && ! $code)
        <p class="muted">{{ trans_choice('scan.station_waiting', $waiting, ['count' => $waiting]) }}</p>
    @endif
    <form method="post" action="{{ route('scanner.station.create') }}">
        @csrf
        <label for="station-name">{{ __('scan.station_name') }}</label>
        <input id="station-name" name="name" maxlength="100" placeholder="{{ __('scan.station_default_name') }}">
        <div class="actions"><button type="submit">{{ __('scan.station_add') }}</button></div>
    </form>

    <h2>{{ __('scan.direct_title') }}</h2>
    <p>{{ __('scan.settings_text') }}</p>
    @if ($name)
        <p class="check-ok">{{ __('scan.connected', ['name' => $name]) }}</p>
    @endif
    <form method="post" action="{{ route('scanner.save') }}">
        @csrf
        <label for="url">{{ __('scan.address') }}</label>
        <input id="url" name="url" value="{{ old('url', $url) }}" maxlength="200" placeholder="192.168.0.20" autocomplete="off" inputmode="url">
        <p class="muted">{{ __('scan.address_hint') }}</p>
        <div class="actions"><button type="submit">{{ __('scan.save') }}</button></div>
    </form>
@endsection
