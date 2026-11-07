@extends('layouts.settings')

@section('title', __('shares.title'))

@section('page')
    <div class="page-head"><h1>{{ __('shares.title') }}</h1></div>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($canSetDays)
        <form method="post" action="{{ route('shares.days') }}">
            @csrf
            <label for="days">{{ __('shares.days') }}</label>
            <input id="days" name="days" type="number" min="1" max="365" value="{{ old('days', $days) }}" required>
            @error('days')<p class="error" role="alert">{{ $message }}</p>@enderror
            <button type="submit">{{ __('shares.save') }}</button>
        </form>
    @else
        <p class="muted">{{ __('shares.days_set', ['days' => $days]) }}</p>
    @endif

    <h2>{{ __('shares.active') }}</h2>
    @if ($links->isEmpty())
        <p class="muted">{{ __('shares.none') }}</p>
    @else
        <ul class="documents">
            @foreach ($links as $link)
                <li>
                    {{ $link->document?->title ?? '–' }}
                    <span class="muted">{{ __('shares.until', ['date' => $link->expires_at->format('d.m.Y H:i')]) }}</span>
                    <form method="post" action="{{ route('shares.revoke', $link->id) }}">
                        @csrf
                        <button type="submit" class="button-quiet button-small">{{ __('shares.revoke') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
