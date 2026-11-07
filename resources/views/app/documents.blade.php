{{-- Dokumente in the phone app: one folder level at a time, the documents in it, new/rename/delete
     folders, and "Scannen". Forms go to the website's routes and come back here (app_back). --}}
@extends('layouts.phone')

@section('title', $current?->name ?? __('app.bar.documents_app'))

@section('head')
    @if ($current)
        <a class="phone-head-back" href="{{ route('app.documents', array_filter(['folder' => $current->parent_id])) }}" aria-label="{{ __('app.docs.up') }}"><x-icon name="chevron-left" /></a>
    @endif
    <h1>{{ $current?->name ?? __('app.bar.documents_app') }}</h1>
    <a class="phone-head-gear" href="{{ route('app.settings') }}" aria-label="{{ __('app.gear.title') }}"><x-icon name="settings" /></a>
@endsection

@section('content')
    @if ($trail !== [])
        <nav class="phone-trail" aria-label="{{ __('app.docs.trail') }}">
            <a href="{{ route('app.documents') }}">{{ __('app.bar.documents_app') }}</a>
            @foreach ($trail as $step)
                <span aria-hidden="true">›</span>
                @if ($loop->last)<span aria-current="page">{{ $step->name }}</span>@else<a href="{{ route('app.documents', ['folder' => $step->id]) }}">{{ $step->name }}</a>@endif
            @endforeach
        </nav>
    @endif

    @if ($scanReady)
        <a class="phone-action" href="{{ route('app.scan', array_filter(['folder' => $current?->id])) }}"><x-icon name="scan" />{{ __('app.scan.title') }}</a>
    @elseif (Gate::allows('settings.manage'))
        <a class="phone-action" href="{{ route('app.scanner') }}"><x-icon name="plug" />{{ __('app.scan.connect') }}</a>
    @endif

    <ul class="phone-list" aria-label="{{ __('folders.title') }}">
        @foreach ($folders as $folder)
            <li class="phone-row">
                <a class="phone-row-main" href="{{ route('app.documents', ['folder' => $folder->id]) }}"><x-icon name="folder" /><span>{{ $folder->name }}</span></a>
                @can('documents.edit')
                    <details class="phone-row-menu">
                        <summary aria-label="{{ __('app.docs.folder_menu', ['name' => $folder->name]) }}"><x-icon name="dots" /></summary>
                        <div class="phone-row-panel">
                            <form method="post" action="{{ route('folders.update', $folder->id) }}">
                                @csrf
                                <input type="hidden" name="app_back" value="{{ $back }}">
                                <input type="hidden" name="parent_id" value="{{ $folder->parent_id }}">
                                @if ($folder->bank_matching)<input type="hidden" name="bank_matching" value="1">@endif
                                <label for="rename-{{ $folder->id }}">{{ __('app.docs.rename') }}</label>
                                <input id="rename-{{ $folder->id }}" name="name" value="{{ $folder->name }}" maxlength="100" required>
                                <button type="submit">{{ __('app.docs.save') }}</button>
                            </form>
                            @unless ($folder->is_default)
                                <form method="post" action="{{ route('folders.destroy', $folder->id) }}" data-confirm="{{ __('app.docs.delete_folder_confirm', ['name' => $folder->name]) }}">
                                    @csrf
                                    <input type="hidden" name="app_back" value="{{ $back }}">
                                    <button type="submit" class="button-danger"><x-icon name="trash" />{{ __('app.docs.delete_folder') }}</button>
                                </form>
                            @endunless
                        </div>
                    </details>
                @endcan
            </li>
        @endforeach
        @foreach ($documents as $document)
            @php($file = $document->files->first())
            <li class="phone-row">
                <a class="phone-row-main" href="{{ route('app.documents.show', $document->id) }}">
                    @if ($file && ($file->thumb_path !== null || str_starts_with($file->mime, 'image/')))
                        <img class="phone-thumb" src="{{ route('documents.thumb', $file->id) }}" alt="" loading="lazy">
                    @else
                        <x-icon name="file-text" />
                    @endif
                    <span>{{ $document->title }}<small class="muted">{{ $document->created_at?->format('d.m.Y') }}</small></span>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($folders->isEmpty() && $documents->isEmpty())
        <p class="muted phone-empty">{{ __('app.docs.empty') }}</p>
    @endif

    @can('documents.edit')
        <details class="phone-new">
            <summary><x-icon name="plus" />{{ __('app.docs.new_folder') }}</summary>
            <form method="post" action="{{ route('folders.store') }}">
                @csrf
                <input type="hidden" name="app_back" value="{{ $back }}">
                <input type="hidden" name="parent_id" value="{{ $current?->id }}">
                <label for="new-folder">{{ __('app.docs.folder_name') }}</label>
                <input id="new-folder" name="name" maxlength="100" required>
                <button type="submit">{{ __('app.docs.create') }}</button>
            </form>
        </details>
    @endcan
@endsection
