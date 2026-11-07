@extends('layouts.settings')

@section('title', __('phone.title'))

@section('page')
    <h1>{{ __('phone.title') }}</h1>
    <p>{{ __('phone.intro') }}</p>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if (! $allowed)
        <p class="muted">{{ __('phone.not_for_advisor') }}</p>
    @elseif ($code)
        {{-- Shown once: the code is stored only as a hash. --}}
        <div class="notice" role="status">
            <p><strong>{{ __('phone.scan_title') }}</strong></p>
            <img class="phone-qr" src="{{ $qr }}" alt="{{ __('phone.scan_title') }}">
            <p>{{ __('phone.type_hint', ['url' => route('phone.connect')]) }}</p>
            <p><code class="pairing-code">{{ $code }}</code></p>
            <p class="muted">{{ __('phone.valid', ['minutes' => $minutes]) }}</p>
        </div>
    @else
        <ol>
            @foreach (__('phone.steps') as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
        <form method="post" action="{{ route('phone.start') }}">
            @csrf
            <div class="actions"><button type="submit"><x-icon name="device-mobile" /> {{ __('phone.start') }}</button></div>
        </form>
    @endif

    <h2>{{ __('phone.devices') }}</h2>
    @if ($devices->isEmpty())
        <p class="muted">{{ __('phone.no_devices') }}</p>
    @else
        <ul class="view-list">
            @foreach ($devices as $device)
                <li>
                    <x-icon name="device-mobile" />
                    <span>{{ $device->name }}@if ($device->id === $current) <small class="muted">({{ __('phone.this_device') }})</small>@endif</span>
                    <span class="muted">{{ __('phone.last_used', ['date' => $device->last_used_at?->format('d.m.Y H:i') ?? '–']) }}</span>
                    <details class="phone-rename">
                        <summary class="button-quiet">{{ __('phone.rename') }}</summary>
                        <form method="post" action="{{ route('phone.rename', $device->id) }}">
                            @csrf
                            <label for="device-{{ $device->id }}" class="visually-hidden">{{ __('phone.name') }}</label>
                            <input id="device-{{ $device->id }}" name="name" value="{{ $device->name }}" maxlength="100" required>
                            <button type="submit">{{ __('phone.save') }}</button>
                        </form>
                    </details>
                    <form method="post" action="{{ route('phone.revoke', $device->id) }}">
                        @csrf
                        <button type="submit" class="button-quiet">{{ __('phone.revoke') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
