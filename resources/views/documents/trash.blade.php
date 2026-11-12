{{-- The Papierkorb as a window (window system): it opens on the right from its row among the folders in Dokumente.
     It behaves like a folder (point 77): rows drag onto a folder row to come back there (A135), the restore button puts one back
     where it was, deleting here is for good after a confirm (A137), "Papierkorb leeren" names the count (A136). --}}
@extends('layouts.app')

@section('title', $name)

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="$name" icon="trash" kind="trash" :url="route('documents.trash')" :close="route('documents.index')">
        @if ($count > 0)
            <x-slot:actions>
                <div class="toolbar">
                    <button type="button" class="icon-button" data-dialog-open="trash-empty" aria-label="{{ __('documents.bulk.empty') }}" data-tooltip="{{ __('documents.bulk.empty') }}"><x-icon name="trash-x" /></button>
                </div>
            </x-slot:actions>
        @endif
        @if (session('status'))
            <p class="check-ok" role="status">{{ session('status') }}</p>
        @endif
        @if ($documents->isEmpty())
            <p class="muted">{{ __('documents.trash_empty') }}</p>
        @else
            <ul class="view-list trash-list">
                @foreach ($documents as $document)
                    <li data-doc="{{ $document->id }}" data-trashed>
                        @include('documents._type-icon')
                        <span class="trash-title"><span data-doc-title>{{ $document->title }}</span>@if ($document->in_print_tray) <span class="muted">({{ __('print_tray.title') }})</span>@endif</span>
                        <span class="muted">{{ __('documents.deleted_at', ['date' => $document->deleted_at?->format('d.m.Y H:i')]) }}</span>
                        <span class="row-actions">
                            <form method="post" action="{{ route('documents.restore', $document->id) }}" data-bulk-form>
                                @csrf
                                <button type="submit" class="control-button" aria-label="{{ __('documents.restore') }}: {{ $document->title }}" data-tooltip="{{ __('documents.restore') }}"><x-icon name="arrow-back-up" /></button>
                            </form>
                            <form method="post" action="{{ route('documents.bulk.purge') }}" data-bulk-form data-confirm-title="{{ __('documents.bulk.purge_title') }}" data-confirm="{{ trans_choice('documents.bulk.purge_text', 1, ['count' => 1, 'title' => $document->title]) }}" data-confirm-ok="{{ __('documents.bulk.purge') }}">
                                @csrf
                                <input type="hidden" name="ids[]" value="{{ $document->id }}">
                                <button type="submit" class="control-button control-danger" aria-label="{{ __('documents.bulk.purge') }}: {{ $document->title }}" data-tooltip="{{ __('documents.bulk.purge') }}"><x-icon name="trash-x" /></button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($count > 0)
            @include('documents._trash-empty')
        @endif
    </x-window>
    </div>
@endsection
