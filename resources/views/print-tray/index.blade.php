@extends('layouts.app')

@section('title', __('print_tray.title'))

@section('content')
    <div class="page-head">
        <h1>{{ __('print_tray.title') }}</h1>
        <form method="post" action="{{ route('print-tray.setup') }}">
            @csrf
            <button type="submit"><x-icon name="printer" /> {{ __('print_tray.setup') }}</button>
        </form>
    </div>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($documents->isEmpty())
        <p class="muted">{{ __('print_tray.empty') }}</p>
    @else
        <ul class="view-list tray-list">
            @foreach ($documents as $document)
                @php($file = $document->files->first())
                <li>
                    @include('documents._thumb', ['frameClass' => 'tray-thumb'])
                    <span class="tray-name">
                        @if ($file)
                            <a href="{{ route('documents.file', $file->id) }}" target="_blank" rel="noopener">{{ $document->title }}</a>
                        @else
                            {{ $document->title }}
                        @endif
                        <small class="muted">{{ __('print_tray.printed_at', ['date' => $document->created_at->format('d.m.Y H:i')]) }}</small>
                    </span>
                    <span class="tray-actions">
                        <form method="post" action="{{ route('print-tray.accept', $document->id) }}">
                            @csrf
                            <button type="submit">{{ __('print_tray.accept') }}</button>
                        </form>
                        @if ($canDelete)
                            <form method="post" action="{{ route('print-tray.destroy', $document->id) }}">
                                @csrf
                                <button type="submit" class="button-quiet">{{ __('documents.delete') }}</button>
                            </form>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    <h2>{{ __('print_tray.printers') }}</h2>
    @if ($printers->isEmpty())
        <p class="muted">{{ __('print_tray.no_printers') }}</p>
    @else
        <ul class="view-list">
            @foreach ($printers as $printer)
                <li>
                    <x-icon name="printer" />
                    <span>{{ $printer->name }}</span>
                    <span class="muted">{{ __('print_tray.last_used', ['date' => $printer->last_used_at?->format('d.m.Y H:i') ?? __('documents.never')]) }}</span>
                    <form method="post" action="{{ route('print-tray.revoke', $printer->id) }}">
                        @csrf
                        <button type="submit" class="button-quiet">{{ __('print_tray.revoke') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <h2>{{ __('print_tray.howto_title') }}</h2>
    <ol class="tray-howto">
        @foreach (__('print_tray.howto') as $step)
            <li>{{ $step }}</li>
        @endforeach
    </ol>
@endsection
