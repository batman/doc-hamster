@extends('layouts.settings')

@section('title', __('shares.title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif

    @if ($canSetDays)
        {{-- PO 2026-11-16: a saved value is changed by click (Feld mit ✓). --}}
        <div class="field">
            <label id="share-days-label">{{ __('shares.days') }}</label>
            <x-edit-in-place name="days" :value="(string) $days" :action="route('shares.days')" :label="__('shares.days')" inputmode="numeric" maxlength="3" aria-labelledby="share-days-label" />
        </div>
    @else
        <p class="field-hint">{{ __('shares.days_set', ['days' => $days]) }}</p>
    @endif

    <h2>{{ __('shares.active') }}</h2>
    @if ($links->isEmpty())
        <x-empty icon="link" :text="__('shares.none')" />
    @else
        <ul class="pick-list">
            @foreach ($links as $link)
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon name="link" /></span>
                    <span>
                        <strong>@if ($link->document)<a href="{{ route('documents.show', $link->document_id) }}">{{ $link->document->title }}</a>@else – @endif</strong>
                        <small>{{ __('shares.until', ['date' => $link->expires_at->format('d.m.Y H:i')]) }}</small>
                    </span>
                    <span class="row-actions">
                        <form method="post" action="{{ route('shares.revoke', $link->id) }}">
                            @csrf
                            <button type="submit" class="control-button control-danger" aria-label="{{ __('shares.revoke') }}: {{ $link->document?->title }}" data-tooltip="{{ __('shares.revoke') }}"><x-icon name="unlink" /></button>
                        </form>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
