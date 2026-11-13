@extends('layouts.app')

@section('title', __('print_tray.title'))

@section('content')
    <div class="page-head">
        <h1>{{ __('print_tray.title') }}</h1>
    </div>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($documents->isEmpty())
        <p class="muted">{{ __('print_tray.empty') }}</p>
        @unless ($hasPrinter)
            {{-- A266: gone once a computer is paired. --}}
            <p class="muted" data-no-printer>{{ __('print_tray.no_printer_hint') }} <a href="{{ route('printers.index') }}">{{ __('print_tray.no_printer_link') }}</a></p>
        @endunless
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
@endsection
