{{-- Folders: the tree on the left, the editor on the right (issue #77). --}}
@extends('layouts.settings')

@section('title', __('folders.title'))

@section('pane')
    <div class="col-head">
        <h2>{{ __('folders.title') }}</h2>
        <button type="button" class="col-fold" data-fold="1" aria-label="{{ __('settings.fold') }}" data-tooltip="{{ __('settings.fold') }}"><x-icon name="chevron-left" /></button>
    </div>
    @if ($rows === [])
        <p class="muted">{{ __('folders.empty') }}</p>
    @else
        <ul class="folder-list">
            @foreach ($rows as $row)
                <li class="folder-d{{ $row['depth'] }}"><a class="folder-link {{ $row['folder']->colorClass() }} @if ($folder?->id === $row['folder']->id) is-selected @endif" href="{{ route('folders.index', ['folder' => $row['folder']->id]) }}"><x-icon :name="$row['folder']->iconName()" /><span>{{ $row['folder']->name }}</span><span class="muted">{{ $counts[$row['folder']->id] ?? 0 }}</span></a></li>
            @endforeach
        </ul>
    @endif
@endsection

@section('page')
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    @if ($folder)
        <div class="col-head">
            <h1>{{ $folder->name }}</h1>
            <a class="icon-button" href="{{ route('folders.index') }}" aria-label="{{ __('documents.close') }}" data-tooltip="{{ __('documents.close') }}"><x-icon name="x" /></a>
        </div>
        <p><a href="{{ route('documents.index', ['folder' => $folder->id]) }}">{{ trans_choice('folders.used', $counts[$folder->id] ?? 0, ['count' => $counts[$folder->id] ?? 0]) }}</a></p>
        <form method="post" action="{{ route('folders.update', $folder->id) }}">
            @csrf
            @include('folders._fields', ['name' => $folder->name, 'parent' => $folder->parent_id, 'self' => $folder->id, 'matching' => $folder->bank_matching, 'locked' => $folder->is_default, 'color' => $folder->color, 'icon' => $folder->icon])
            <div class="actions">
                <button type="submit">{{ __('folders.save') }}</button>
                @unless ($folder->is_default)
                    <button type="button" class="button-danger" data-dialog-open="delete-folder">{{ __('folders.delete') }}</button>
                @endunless
            </div>
        </form>

        @unless ($folder->is_default)
        <div class="dialog-backdrop" data-dialog="delete-folder" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-folder-title">
                <h2 id="delete-folder-title">{{ __('folders.delete_title') }}</h2>
                <p>{{ __('folders.delete_text', ['name' => $folder->name]) }}</p>
                @can('documents.trash')
                    <p>{{ __('folders.delete_content_text') }}</p>
                @endcan
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('folders.cancel') }}</button>
                    <form method="post" action="{{ route('folders.destroy', $folder->id) }}">
                        @csrf
                        <button type="submit" class="button-quiet">{{ __('folders.delete_keep') }}</button>
                    </form>
                    @can('documents.trash')
                        <form method="post" action="{{ route('folders.destroy', $folder->id) }}">
                            @csrf
                            <input type="hidden" name="with_content" value="1">
                            <button type="submit" class="button-danger">{{ __('folders.delete_content') }}</button>
                        </form>
                    @endcan
                </div>
            </div>
        </div>
        @endunless
    @else
        <div class="col-head"><h1>{{ __('folders.new') }}</h1></div>
        <form method="post" action="{{ route('folders.store') }}">
            @csrf
            @include('folders._fields', ['name' => '', 'parent' => request('parent'), 'self' => null, 'matching' => false, 'locked' => false, 'color' => null, 'icon' => null])
            <div class="actions"><button type="submit">{{ __('folders.create') }}</button></div>
        </form>

        @can('documents.upload')
            {{-- Folder import (PO, 2026-11-07): a new folder with the picked folder's name, files go up one by one (app.js). --}}
            <form class="folder-import" data-folder-import
                  data-start="{{ route('folders.import') }}" data-file="{{ route('folders.import.file', ['id' => '__ID__']) }}"
                  data-running="{{ __('folders.import_running') }}" data-done="{{ __('folders.import_done') }}" data-skipped="{{ __('folders.import_skipped') }}"
                  data-failed="{{ __('folders.import_failed') }}" data-empty="{{ __('folders.import_empty') }}" data-unsupported="{{ __('folders.import_unsupported') }}">
                <h2>{{ __('folders.import') }}</h2>
                <p class="muted">{{ __('folders.import_hint') }}</p>
                <label for="import_parent">{{ __('folders.parent') }}</label>
                <select id="import_parent" name="import_parent">
                    <option value="">{{ __('folders.top') }}</option>
                    @foreach ($rows as $row)
                        @if ($row['depth'] < \App\Domain\Documents\Folder::MAX_DEPTH)
                            <option value="{{ $row['folder']->id }}" @selected(request('parent') === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                        @endif
                    @endforeach
                </select>
                <label class="button" for="import-folder"><x-icon name="folder-up" /> {{ __('folders.import_pick') }}</label>
                <input id="import-folder" class="visually-hidden" type="file" webkitdirectory directory multiple>
                <progress hidden></progress>
                <p data-import-status role="status" aria-live="polite"></p>
                <a href="#" data-import-open hidden>{{ __('folders.import_open') }}</a>
            </form>
        @endcan
    @endif
@endsection
