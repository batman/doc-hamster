@extends('layouts.settings')

@section('title', __('shares.title'))

@section('page')
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($canSetDays)
        {{-- Point 170.2: one value – the ✓ in the field saves it (Enter or ✓, FRAMEWORK.md "Properties module"). --}}
        <form method="post" action="{{ route('shares.days') }}" data-autosave>
            @csrf
            <label for="days">{{ __('shares.days') }}</label>
            <div class="field-addon">
                <input id="days" name="days" type="number" min="1" max="365" value="{{ old('days', $days) }}" required>
                <span class="addon-end"><button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('shares.save') }}" data-tooltip="{{ __('shares.save') }}" hidden><x-icon name="check" /></button></span>
            </div>
            @error('days')<p class="error" role="alert">{{ $message }}</p>@enderror
            <noscript><button type="submit">{{ __('shares.save') }}</button></noscript>
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
                        <button type="submit" class="button-quiet">{{ __('shares.revoke') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
