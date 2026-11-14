{{-- One document in the tax advisor's view (point 210.5): read-only details, the picture (or the PDF's first page as
     small image where there is one) and the downloads of its files. Opened as a window right of the list. --}}
@extends('layouts.app')

@section('title', $document->title)

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="$document->title" icon="file-text" kind="advisor-document" :url="route('advisor.document', $document->id)" :close="route('advisor.index', ['view' => 'documents'])" fit="medium" content="detail">
        @php $file = $document->files->first(); @endphp
        @if ($file)
            <x-slot:actions>
                <a class="icon-button" href="{{ route('advisor.file', [$file->id, 'download' => 1]) }}" aria-label="{{ __('advisor.download') }}" data-tooltip="{{ __('advisor.download') }}"><x-icon name="download" /></a>
            </x-slot:actions>
        @endif

        @if ($file?->isImage())
            <span class="thumb-frame detail-doc"><img class="thumb" src="{{ route('advisor.file', $file->id) }}" alt="" draggable="false"></span>
        @endif

        <ul class="prop-list">
            <li class="prop-row"><span class="prop-name">{{ __('advisor.doc_date') }}</span><span class="prop-value">{{ $document->dateBy('doc')->format('d.m.Y') }}</span></li>
            @foreach ($document->matches as $match)
                @if ($match->transaction)
                    <li class="prop-row"><span class="prop-name">{{ __('advisor.booking') }}</span><span class="prop-value">{{ $match->transaction->booking_date->format('d.m.Y') }} · {{ $match->transaction->signedAmount() }} · {{ $match->transaction->account->name }} · {{ $match->transaction->counterparty }}</span></li>
                @endif
            @endforeach
            @if ($document->tags->isNotEmpty())
                <li class="prop-row"><span class="prop-name">{{ __('advisor.tags') }}</span><span class="prop-value">{{ $document->tags->pluck('name')->implode(', ') }}</span></li>
            @endif
            @if ($document->note)
                <li class="prop-row"><span class="prop-name">{{ __('advisor.note') }}</span><span class="prop-value">{{ $document->note }}</span></li>
            @endif
        </ul>

        <ul class="pick-list">
            @foreach ($document->files as $each)
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon :name="$each->typeIcon()" /></span>
                    <span><strong>{{ $each->downloadName($document->title) }}</strong><small>{{ \App\Http\Controllers\BackupController::human($each->size) }}</small></span>
                    <a class="icon-button" href="{{ route('advisor.file', [$each->id, 'download' => 1]) }}" aria-label="{{ __('advisor.download') }}: {{ $each->downloadName($document->title) }}" data-tooltip="{{ __('advisor.download') }}"><x-icon name="download" /></a>
                </li>
            @endforeach
        </ul>
    </x-window>
    </div>
@endsection
