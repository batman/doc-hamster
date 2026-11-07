{{-- Connect a scanner from the phone app: search the network, enter an address, or link a Scan Station.
     Only admins; everyone else is told to ask one. --}}
@extends('layouts.phone')

@section('title', __('app.scanner.title'))

@section('head')
    <a class="phone-head-back" href="{{ route('app.documents') }}" aria-label="{{ __('app.docs.back') }}"><x-icon name="chevron-left" /></a>
    <h1>{{ __('app.scanner.title') }}</h1>
@endsection

@section('content')
    @unless ($admin)
        <p class="notice">{{ __('app.scanner.ask_admin') }}</p>
    @else
        <p class="muted">{{ __('app.scanner.intro') }}</p>

        <form method="post" action="{{ route('app.scanner.search') }}" data-busy="{{ __('app.scanner.searching') }}">
            @csrf
            <button type="submit" class="phone-action phone-wide"><x-icon name="search" />{{ __('app.scanner.search') }}</button>
        </form>

        @if (is_array($found))
            @if ($found === [])
                <p class="muted">{{ __('app.scanner.none_found') }}</p>
            @else
                <ul class="phone-list">
                    @foreach ($found as $scanner)
                        <li class="phone-row">
                            <form class="phone-row-main" method="post" action="{{ route('scanner.save') }}">
                                @csrf
                                <input type="hidden" name="app_back" value="{{ route('app.scan', absolute: false) }}">
                                <input type="hidden" name="url" value="{{ $scanner['url'] }}">
                                <x-icon name="scan" /><span>{{ $scanner['model'] }}<small class="muted">{{ $scanner['url'] }}</small></span>
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
                <input type="hidden" name="app_back" value="{{ route('app.scan', absolute: false) }}">
                <label for="scanner-url">{{ __('scan.address') }}</label>
                <input id="scanner-url" name="url" maxlength="200" inputmode="url" placeholder="192.0.2.10" required>
                <button type="submit">{{ __('app.scanner.use') }}</button>
            </form>
        </details>

        <h2>{{ __('app.scanner.station_title') }}</h2>
        <p class="muted">{{ __('app.scanner.station_text') }}</p>
        @if ($code)
            <div class="notice" role="status">
                <p>{{ __('scan.station_code_text') }}</p>
                <p><code>{{ $code }}</code></p>
                <p class="muted">{{ __('app.scanner.station_on_computer') }}</p>
            </div>
        @else
            <form method="post" action="{{ route('scanner.station.create') }}">
                @csrf
                <input type="hidden" name="app_back" value="{{ $back }}">
                <button type="submit" class="phone-action phone-wide"><x-icon name="plug" />{{ __('scan.station_add') }}</button>
            </form>
        @endif
    @endunless
@endsection
