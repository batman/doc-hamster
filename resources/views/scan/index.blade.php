{{-- Scan from the printer or scanner (issue #77): pages so far, the form for the next one, "Fertig". --}}
@extends('layouts.app')

@section('title', __('scan.title'))

@section('content')
    <div class="page-head"><h1>{{ __('scan.title') }}</h1></div>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif
    @if ($failure)
        <p class="error" role="alert">{{ $failure }}</p>
    @endif
    @if ($stationOffline)
        <p class="notice" role="status">{{ __('scan.station_offline') }}</p>
    @endif

    @unless ($configured)
        <div class="notice">
            <p>{{ __('scan.not_set_up') }}</p>
            @can('settings.manage')
                <p><a href="{{ route('scanner.show') }}">{{ __('scan.set_up') }}</a></p>
            @endcan
        </div>
    @else
        <p class="muted">{{ __('scan.with', ['name' => $name]) }}</p>

        @if ($pages > 0)
            <section class="scan-pages" aria-label="{{ trans_choice('scan.pages', $pages, ['count' => $pages]) }}">
                <h2>{{ trans_choice('scan.pages', $pages, ['count' => $pages]) }}</h2>
                <ul class="scan-strip">
                    @foreach (range(1, $pages) as $number)
                        <li>
                            <span class="thumb-frame"><img class="thumb" src="{{ route('scan.preview', $number) }}" alt="{{ __('scan.page', ['number' => $number]) }}"></span>
                            <form method="post" action="{{ route('scan.remove', $number) }}">
                                @csrf
                                <button type="submit" class="button-quiet" aria-label="{{ __('scan.remove', ['number' => $number]) }}">{{ $number }} · {{ __('scan.remove_short') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($waiting)
            {{-- A Scan Station is scanning: this page looks again every few seconds (app.js). --}}
            <p class="notice" role="status" data-scan-wait>{{ __('scan.waiting') }}</p>
        @endif

        <form method="post" action="{{ route('scan.page') }}" data-busy="{{ __('scan.scanning') }}" @if ($waiting) hidden @endif>
            @csrf
            <input type="hidden" name="folder" value="{{ old('folder', $folder) }}">
            <fieldset>
                <legend>{{ $pages > 0 ? __('scan.next') : __('scan.first') }}</legend>
                <label for="source">{{ __('scan.source') }}</label>
                <select id="source" name="source">
                    @if ($feeder)
                        <option value="feeder" @selected(old('source', 'feeder') === 'feeder')>{{ __('scan.source_feeder') }}</option>
                    @endif
                    <option value="platen" @selected(old('source', $feeder ? 'feeder' : 'platen') === 'platen')>{{ __('scan.source_platen') }}</option>
                </select>
                <label for="color">{{ __('scan.color') }}</label>
                <select id="color" name="color">
                    @foreach (['color', 'gray', 'bw'] as $mode)
                        <option value="{{ $mode }}" @selected(old('color', 'color') === $mode)>{{ __('scan.color_'.$mode) }}</option>
                    @endforeach
                </select>
                <label for="dpi">{{ __('scan.quality') }}</label>
                <select id="dpi" name="dpi">
                    @foreach ([150, 200, 300] as $value)
                        <option value="{{ $value }}" @selected((int) old('dpi', $dpi) === $value)>{{ __('scan.dpi', ['dpi' => $value]) }}</option>
                    @endforeach
                </select>
            </fieldset>
            <p class="muted">{{ __('scan.hint') }}</p>
            <div class="actions"><button type="submit">{{ $pages > 0 ? __('scan.scan_next') : __('scan.scan_first') }}</button></div>
        </form>

        @if ($pages > 0)
            <form method="post" action="{{ route('scan.finish') }}">
                @csrf
                <h2>{{ __('scan.finish_title') }}</h2>
                <label for="title">{{ __('scan.name') }}</label>
                <input id="title" name="title" maxlength="250" placeholder="{{ __('scan.name_placeholder') }}" value="{{ old('title') }}">
                <label for="finish-folder">{{ __('folders.folder') }}</label>
                <select id="finish-folder" name="folder">
                    <option value="">{{ __('folders.default') }}</option>
                    @foreach ($folders as $row)
                        @unless ($row['folder']->is_default)
                            <option value="{{ $row['folder']->id }}" @selected(old('folder', $folder) === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                        @endunless
                    @endforeach
                </select>
                <div class="actions">
                    <button type="submit">{{ __('scan.finish') }}</button>
                </div>
            </form>
            <form method="post" action="{{ route('scan.cancel') }}">
                @csrf
                <button type="submit" class="button-danger">{{ __('scan.cancel') }}</button>
            </form>
        @endif
    @endunless
@endsection
