{{-- Posteingang (point 153, A327): one window with everything that came in, newest first, each row with its source.
     Tabs per source with their own red count (Badges inbox-<source>); a row leaves when it is opened, moved or marked ✓.
     Rows are the document rows of Dokumente (data-doc: drag onto folders, rename, details). JS: modules/inbox.js. --}}
@extends('layouts.app')

@section('title', __('inbox.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('inbox.title')" icon="inbox" kind="inbox" :url="route('inbox.index', $source !== null ? ['source' => $source] : [])" :close="route('home')">
        <x-slot:actions>
            <div class="toolbar">
                {{-- 153.4: the same upload as the main menu's button (into "Dokumente allgemein"). --}}
                <form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-inbox-upload data-failed="{{ __('documents.bulk.failed') }}">
                    @csrf
                    <label class="icon-button" data-tooltip="{{ __('documents.upload') }}">
                        <input class="visually-hidden" type="file" name="files[]" multiple accept="image/*,application/pdf" aria-label="{{ __('documents.upload') }}">
                        <x-icon name="upload" />
                    </label>
                    <noscript><button type="submit" class="button-small">{{ __('documents.upload') }}</button></noscript>
                </form>
                @if ($documents->isNotEmpty())
                    <form method="post" action="{{ route('inbox.done-all') }}" data-inbox-action>
                        @csrf
                        @if ($source !== null)<input type="hidden" name="source" value="{{ $source }}">@endif
                        <button type="submit" class="icon-button" aria-label="{{ __('inbox.done_all') }}" data-tooltip="{{ __('inbox.done_all') }}"><x-icon name="checks" /></button>
                    </form>
                @endif
            </div>
        </x-slot:actions>

        <nav class="tab-bar" aria-label="{{ __('inbox.tabs_label') }}">
            @foreach ([null, ...\App\Domain\Documents\Inbox::TABS] as $tab)
                @php $key = $tab === null ? \App\Http\Badges::INBOX : \App\Http\Badges::inbox($tab); $count = $badges[$key] ?? 0; @endphp
                <a class="tab" href="{{ route('inbox.index', $tab !== null ? ['source' => $tab] : []) }}" data-inbox-tab @if ($tab === $source) aria-current="page" @endif>
                    @if ($tab !== null)<x-icon :name="\App\Domain\Documents\Inbox::ICONS[$tab]" />@endif
                    <span>{{ $tab === null ? __('inbox.all') : __('inbox.sources.'.$tab) }}</span>
                    <span class="badge badge-alert" data-badge="{{ $key }}" @if ($count === 0) hidden @endif>{{ $count > 9 ? '9+' : $count }}</span>
                    @if ($tab === $source)<span class="tab-mark"></span>@endif
                </a>
            @endforeach
        </nav>

        @if (session('status'))
            <p class="check-ok" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        @if ($documents->isEmpty())
            <p class="list-empty">{{ $source !== null && $source !== 'api' ? __('inbox.empty_source.'.$source) : __('inbox.empty') }}</p>
            @unless ($hasPrinter)
                {{-- A266: gone once a computer is paired. --}}
                <p class="muted" data-no-printer>{{ __('print_tray.no_printer_hint') }} <a href="{{ route('printers.index') }}">{{ __('print_tray.no_printer_link') }}</a></p>
            @endunless
        @else
            <ul class="view-list">
                @foreach ($documents as $document)
                    @php $from = \App\Domain\Documents\Inbox::sourceOf($document); $fromLabel = __('inbox.from', ['source' => __('inbox.sources.'.$from)]); @endphp
                    <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc="{{ $document->id }}" data-doc-file="{{ $document->dragFile() }}" data-folder="{{ $document->folder_id }}" data-source="{{ $from }}">
                        @include('documents._type-icon')
                        <a href="{{ route('documents.show', $document->id) }}" data-window><span data-doc-title>{{ $document->title }}</span></a>
                        <span class="muted">{{ $document->created_at->format('d.m.Y H:i') }}</span>
                        <span class="type-icon" role="img" aria-label="{{ $fromLabel }}" data-tooltip="{{ $fromLabel }}"><x-icon :name="\App\Domain\Documents\Inbox::ICONS[$from]" /></span>
                        @include('documents._row-actions', ['inboxDone' => true])
                    </li>
                @endforeach
            </ul>
        @endif
    </x-window>
    </div>
@endsection
