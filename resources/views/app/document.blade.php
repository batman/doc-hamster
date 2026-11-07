{{-- One document in the phone app: look at it, rename, move, download, share, print, delete. --}}
@extends('layouts.phone')

@section('title', $document->title)

@section('head')
    <a class="phone-head-back" href="{{ $list }}" aria-label="{{ __('app.docs.back') }}"><x-icon name="chevron-left" /></a>
    <h1>{{ $document->title }}</h1>
@endsection

@section('content')
    @if ($file)
        <a class="phone-preview" href="{{ route('documents.file', $file->id) }}">
            @if (str_starts_with($file->mime, 'image/'))
                <img src="{{ route('documents.file', $file->id) }}" alt="{{ $document->title }}">
            @elseif ($file->thumb_path !== null)
                <img src="{{ route('documents.thumb', $file->id) }}" alt="{{ $document->title }}">
            @else
                <x-icon name="file-type-pdf" /><span>{{ __('app.docs.open') }}</span>
            @endif
        </a>

        <div class="phone-actions">
            <a class="phone-action" href="{{ route('documents.download', $file->id) }}" download><x-icon name="download" />{{ __('app.docs.download') }}</a>
            <button type="button" class="phone-action" data-share="{{ route('documents.file', $file->id) }}" data-share-title="{{ $document->title }}" data-share-type="{{ $file->mime }}" hidden><x-icon name="send" />{{ __('app.docs.share') }}</button>
            @if ($canPrint)
                <form method="post" action="{{ route('documents.print', $file->id) }}">
                    @csrf
                    <input type="hidden" name="app_back" value="{{ $back }}">
                    <button type="submit" class="phone-action"><x-icon name="printer" />{{ __('app.docs.print') }}</button>
                </form>
            @endif
        </div>
    @endif

    @can('documents.edit')
        <form class="phone-form" method="post" action="{{ route('documents.update', $document->id) }}">
            @csrf
            <input type="hidden" name="app_back" value="{{ $back }}">
            {{-- The website's form also carries note and tags; keep them as they are. --}}
            <input type="hidden" name="note" value="{{ $document->note }}">
            @foreach ($document->tags as $tag)
                <input type="hidden" name="tags[]" value="{{ $tag->id }}">
            @endforeach
            <label for="doc-title">{{ __('app.docs.name') }}</label>
            <input id="doc-title" name="title" value="{{ $document->title }}" maxlength="250" required>
            <label for="doc-folder">{{ __('folders.folder') }}</label>
            <select id="doc-folder" name="folder">
                <option value="">{{ __('app.docs.no_folder') }}</option>
                @foreach ($folders as $row)
                    @unless ($row['folder']->is_default)
                        <option value="{{ $row['folder']->id }}" @selected($document->folder_id === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                    @endunless
                @endforeach
            </select>
            <button type="submit">{{ __('app.docs.save') }}</button>
        </form>
    @endcan

    @can('documents.trash')
        <form method="post" action="{{ route('documents.destroy', $document->id) }}" data-confirm="{{ __('app.docs.delete_confirm') }}">
            @csrf
            <input type="hidden" name="app_back" value="{{ $list }}">
            <button type="submit" class="button-danger phone-wide"><x-icon name="trash" />{{ __('app.docs.delete') }}</button>
        </form>
    @endcan
@endsection
