{{-- Folders on the window system (docs/design/FRAMEWORK.md §5): the list is the root window,
     editing a folder or making a new one opens as a window to its right (issue #77, PO 2026-11-07).
     Ids and dialog names carry the folder's id, so two folders can be open side by side. --}}
@extends('layouts.app')

@section('title', $folder?->name ?? __('folders.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('folders.title')" icon="folder" :url="route('folders.index')">
        <x-slot:actions>
            <a class="icon-button" href="{{ route('folders.index', ['new' => 1]) }}" data-window aria-label="{{ __('folders.add') }}" data-tooltip="{{ __('folders.add') }}"><x-icon name="folder-plus" /></a>
        </x-slot:actions>

        @if (! $folder && ! $creating)
            @if (session('status'))
                <p class="check-ok" role="status">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif
        @endif

        @if ($rows === [])
            <p class="muted">{{ __('folders.empty') }}</p>
        @else
            <ul class="folder-list">
                @foreach ($rows as $row)
                    <li class="folder-d{{ $row['depth'] }}"><a class="folder-link {{ $row['folder']->colorClass() }} @if ($folder?->id === $row['folder']->id) is-selected @endif" href="{{ route('folders.index', ['folder' => $row['folder']->id]) }}" data-window><x-icon :name="$row['folder']->iconName()" /><span>{{ $row['folder']->name }}</span><span class="muted">{{ $counts[$row['folder']->id] ?? 0 }}</span></a></li>
                @endforeach
            </ul>
        @endif
    </x-window>

    @if ($folder)
        <x-window :title="$folder->name" :icon="$folder->iconName()" :url="route('folders.index', ['folder' => $folder->id])" :close="route('folders.index')" :class="$folder->colorClass()">
            @if (session('status'))
                <p class="check-ok" role="status">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif
            <p><a href="{{ route('documents.index', ['folder' => $folder->id]) }}">{{ trans_choice('folders.used', $counts[$folder->id] ?? 0, ['count' => $counts[$folder->id] ?? 0]) }}</a></p>
            <form method="post" action="{{ route('folders.update', $folder->id) }}">
                @csrf
                @include('folders._fields', ['name' => $folder->name, 'parent' => $folder->parent_id, 'self' => $folder->id, 'matching' => $folder->bank_matching, 'locked' => $folder->is_default, 'color' => $folder->color, 'icon' => $folder->icon])
                <div class="actions">
                    <button type="submit">{{ __('folders.save') }}</button>
                    @unless ($folder->is_default)
                        <button type="button" class="button-danger" data-dialog-open="delete-folder-{{ $folder->id }}">{{ __('folders.delete') }}</button>
                    @endunless
                </div>
            </form>

            @unless ($folder->is_default)
                <div class="dialog-backdrop" data-dialog="delete-folder-{{ $folder->id }}" hidden>
                    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-folder-{{ $folder->id }}-title">
                        <h2 id="delete-folder-{{ $folder->id }}-title">{{ __('folders.delete_title') }}</h2>
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
                                {{-- With content (taken over from #114): subfolders go, receipts land in the Papierkorb. --}}
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
        </x-window>
    @elseif ($creating)
        <x-window :title="__('folders.new')" icon="folder-plus" :url="route('folders.index', array_filter(['new' => 1, 'parent' => request('parent')]))" :close="route('folders.index')">
            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif
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
                    <label for="import-parent-new">{{ __('folders.parent') }}</label>
                    <select id="import-parent-new" name="import_parent">
                        <option value="">{{ __('folders.top') }}</option>
                        @foreach ($rows as $row)
                            @if ($row['depth'] < \App\Domain\Documents\Folder::MAX_DEPTH)
                                <option value="{{ $row['folder']->id }}" @selected(request('parent') === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    <label class="button" for="import-folder-new"><x-icon name="folder-up" /> {{ __('folders.import_pick') }}</label>
                    <input id="import-folder-new" class="visually-hidden" type="file" webkitdirectory directory multiple>
                    <progress hidden></progress>
                    <p data-import-status role="status" aria-live="polite"></p>
                    <a href="#" data-import-open hidden>{{ __('folders.import_open') }}</a>
                </form>
            @endcan
        </x-window>
    @endif
    </div>
@endsection
