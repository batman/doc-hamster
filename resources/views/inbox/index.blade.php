{{-- Posteingang (point 153, A327): one window with everything that came in, newest first, each row with its source.
     Tabs per source with their own red count (Badges inbox-<source>); a row leaves when it is filed (A358-A361): dragged onto
     a folder, "Ablegen in …" (folder menu per row, also for several picked rows) or ✓ (into "Dokumente allgemein");
     point 196: checkboxes pick several, the selection bar files them in one go.
     Rows are the document rows of Dokumente (data-doc: drag onto folders, rename, details). JS: modules/inbox.js. --}}
@extends('layouts.app')

@section('title', __('inbox.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('inbox.title')" icon="inbox" kind="inbox" :url="route('inbox.index', $source !== null ? ['source' => $source] : [])" :close="route('home')">
        <x-slot:actions>
            <div class="toolbar">
                {{-- A359: Dokumente as the next window on the right; rows dragged onto its folder rows are filed there. --}}
                {{-- Point 226: "Auswählen" shows the checkboxes (documents-pick.js). --}}
                @if ($documents->isNotEmpty() && Gate::allows('documents.edit'))
                    <span class="switcher is-actions" role="group" aria-label="{{ __('documents.pick.toggle') }}"><button type="button" data-pick-toggle aria-pressed="false" aria-label="{{ __('documents.pick.toggle') }}" data-tooltip="{{ __('documents.pick.toggle') }}"><x-icon name="square-check" /></button></span>
                @endif
                <a class="icon-button" href="{{ route('documents.index') }}" data-window aria-label="{{ __('inbox.open_folders') }}" data-tooltip="{{ __('inbox.open_folders') }}"><x-icon name="layout-columns" /></a>
                @if ($documents->isNotEmpty())
                    <form method="post" action="{{ route('inbox.done-all') }}" data-inbox-action>
                        @csrf
                        @if ($source !== null)<input type="hidden" name="source" value="{{ $source }}">@endif
                        <button type="submit" class="icon-button" aria-label="{{ __('inbox.done_all') }}" data-tooltip="{{ __('inbox.done_all') }}"><x-icon name="checks" /></button>
                    </form>
                @endif
                {{-- Point 182: the upload lives here now (file button "Hochladen"); new files have no folder (A358). --}}
                <form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-inbox-upload data-failed="{{ __('documents.bulk.failed') }}">
                    @csrf
                    <label class="button button-small file-button" data-tooltip="{{ __('inbox.upload_hint') }}">
                        <input class="visually-hidden" type="file" name="files[]" multiple accept="image/*,application/pdf">
                        <x-icon name="upload" />{{ __('inbox.upload') }}
                    </label>
                    <noscript><button type="submit" class="button-small">{{ __('inbox.upload') }}</button></noscript>
                </form>
            </div>
        </x-slot:actions>

        <nav class="tab-bar" aria-label="{{ __('inbox.tabs_label') }}">
            @foreach ([null, ...\App\Domain\Documents\Inbox::TABS] as $tab)
                @php $key = $tab === null ? \App\Http\Badges::INBOX : \App\Http\Badges::inbox($tab); $count = $badges[$key] ?? 0; @endphp
                <a class="tab" href="{{ route('inbox.index', $tab !== null ? ['source' => $tab] : []) }}" data-inbox-tab @if ($tab === $source) aria-current="page" @endif>
                    @if ($tab !== null)<x-icon :name="\App\Domain\Documents\Inbox::ICONS[$tab]" />@endif
                    <span>{{ $tab === null ? __('inbox.all') : __('inbox.sources.'.$tab) }}</span>
                    <span class="badge badge-alert" data-badge="{{ $key }}" @if ($count === 0) hidden @endif>{{ $count > 99 ? '99+' : $count }}</span>
                    @if ($tab === $source)<span class="tab-mark"></span>@endif
                </a>
            @endforeach
        </nav>

        @if (session('status'))
            <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
        @endif
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        @if ($documents->isEmpty())
            @if ($source !== null && $source !== 'api')
                <x-empty :icon="\App\Domain\Documents\Inbox::ICONS[$source]" :text="__('inbox.empty_source.'.$source)" />
            @else
                {{-- Point 230: the upload sits in the toolbar; the empty state only says so. --}}
                <x-empty icon="inbox" :text="__('empty.inbox')" />
            @endif
            @unless ($hasPrinter)
                {{-- A266: gone once a computer is paired. --}}
                <p class="notice" data-no-printer>{{ __('print_tray.no_printer_hint') }} <a href="{{ route('printers.index') }}">{{ __('print_tray.no_printer_link') }}</a></p>
            @endunless
        @else
            @php $canPick = Gate::allows('documents.edit'); @endphp
            @if ($canPick)
                {{-- Point 196: a checkbox before each row, this one picks all; the selection bar files them at once. --}}
                <label class="pick-all"><input type="checkbox" data-pick-all>{{ __('documents.pick.all') }}</label>
            @endif
            <ul class="view-list">
                @foreach ($documents as $document)
                    @php $from = \App\Domain\Documents\Inbox::sourceOf($document); $fromLabel = __('inbox.from', ['source' => __('inbox.sources.'.$from)]); @endphp
                    <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc="{{ $document->id }}" data-doc-file="{{ $document->dragFile() }}" data-folder="{{ $document->folder_id }}" data-source="{{ $from }}" data-doc-row tabindex="0">
                        @if ($canPick)
                            @include('documents._pick')
                        @endif
                        @include('documents._type-icon')
                        <a href="{{ route('documents.show', $document->id) }}" data-window tabindex="-1"><span data-doc-title>{{ $document->title }}</span></a>
                        <span class="muted">{{ $document->created_at->format('d.m.Y H:i') }}</span>
                        <span class="type-icon" role="img" aria-label="{{ $fromLabel }}" data-tooltip="{{ $fromLabel }}"><x-icon :name="\App\Domain\Documents\Inbox::ICONS[$from]" /></span>
                        @include('documents._row-actions', ['inboxDone' => true])
                    </li>
                @endforeach
            </ul>
            @if ($canPick)
                @include('documents._pick-bar', ['doneUrl' => route('inbox.done-all', $source !== null ? ['source' => $source] : [])])
                @include('inbox._folder-menu')
            @endif
        @endif
    </x-window>
    </div>
@endsection
