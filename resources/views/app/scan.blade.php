{{-- Scannen in the phone app: "Eine Seite" or "Mehrere Seiten", then a name and a folder (or none). --}}
@extends('layouts.phone')

@php
    $mode = request()->query('mode') === 'many' ? 'many' : (request()->query('mode') === 'one' ? 'one' : null);
    $here = fn (?string $m) => route('app.scan', array_filter(['folder' => $folder, 'mode' => $m]), false);
    $source = $mode === 'many' && $feeder ? 'feeder' : 'platen';
@endphp

@section('title', __('app.scan.title'))

@section('head')
    <a class="phone-head-back" href="{{ route('app.documents', array_filter(['folder' => $folder])) }}" aria-label="{{ __('app.docs.back') }}"><x-icon name="chevron-left" /></a>
    <h1>{{ __('app.scan.title') }}</h1>
@endsection

@section('content')
    @if ($failure)
        <p class="error" role="alert">{{ $failure }}</p>
    @endif
    @if ($stationOffline)
        <p class="notice" role="status">{{ __('scan.station_offline') }}</p>
    @endif
    @if ($name)
        <p class="muted">{{ __('scan.with', ['name' => $name]) }}</p>
    @endif

    @if ($pages > 0)
        <ul class="scan-strip phone-strip" aria-label="{{ trans_choice('scan.pages', $pages, ['count' => $pages]) }}">
            @foreach (range(1, $pages) as $number)
                <li>
                    <span class="thumb-frame"><img class="thumb" src="{{ route('scan.preview', $number) }}" alt="{{ __('scan.page', ['number' => $number]) }}"></span>
                    <form method="post" action="{{ route('scan.remove', $number) }}">
                        @csrf
                        <input type="hidden" name="app_back" value="{{ $here($mode) }}">
                        <button type="submit" class="button-quiet" aria-label="{{ __('scan.remove', ['number' => $number]) }}"><x-icon name="x" /></button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($waiting)
        <p class="notice" role="status" data-scan-wait>{{ __('scan.waiting') }}</p>
    @elseif ($pages === 0)
        <div class="scan-choice">
            @foreach (['one' => 'file-text', 'many' => 'layout-cards'] as $choice => $icon)
                <form method="post" action="{{ route('scan.page') }}" data-busy="{{ __('scan.scanning') }}">
                    @csrf
                    <input type="hidden" name="app_back" value="{{ $here($choice) }}">
                    <input type="hidden" name="folder" value="{{ $folder }}">
                    <input type="hidden" name="source" value="{{ $choice === 'many' && $feeder ? 'feeder' : 'platen' }}">
                    <input type="hidden" name="color" value="color">
                    <input type="hidden" name="dpi" value="{{ $dpi }}">
                    <button type="submit" class="scan-big"><x-icon :name="$icon" /><span>{{ __('app.scan.'.$choice) }}</span><small>{{ __('app.scan.'.$choice.'_hint') }}</small></button>
                </form>
            @endforeach
        </div>
    @else
        @if ($mode !== 'one')
            <form method="post" action="{{ route('scan.page') }}" data-busy="{{ __('scan.scanning') }}">
                @csrf
                <input type="hidden" name="app_back" value="{{ $here('many') }}">
                <input type="hidden" name="folder" value="{{ $folder }}">
                <input type="hidden" name="source" value="{{ $source }}">
                <input type="hidden" name="color" value="color">
                <input type="hidden" name="dpi" value="{{ $dpi }}">
                <button type="submit" class="phone-action phone-wide"><x-icon name="plus" />{{ __('app.scan.next') }}</button>
            </form>
        @endif

        <form class="phone-form" method="post" action="{{ route('scan.finish') }}">
            @csrf
            <input type="hidden" name="app_back" value="{{ route('app.documents', array_filter(['folder' => $folder]), false) }}">
            <h2>{{ __('app.scan.done_title') }}</h2>
            <label for="scan-title">{{ __('app.docs.name') }}</label>
            <input id="scan-title" name="title" maxlength="250" placeholder="{{ __('scan.name_placeholder') }}" value="{{ old('title') }}">
            <label for="scan-folder">{{ __('folders.folder') }}</label>
            <select id="scan-folder" name="folder">
                <option value="">{{ __('app.docs.no_folder') }}</option>
                @foreach ($folders as $row)
                    @unless ($row['folder']->is_default)
                        <option value="{{ $row['folder']->id }}" @selected(old('folder', $folder) === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                    @endunless
                @endforeach
            </select>
            <button type="submit">{{ __('app.scan.save') }}</button>
        </form>
        <form method="post" action="{{ route('scan.cancel') }}">
            @csrf
            <input type="hidden" name="app_back" value="{{ $here(null) }}">
            <button type="submit" class="button-quiet phone-wide">{{ __('scan.cancel') }}</button>
        </form>
    @endif
@endsection
