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
                <li class="folder-d{{ $row['depth'] }}"><a class="folder-link @if ($folder?->id === $row['folder']->id) is-selected @endif" href="{{ route('folders.index', ['folder' => $row['folder']->id]) }}"><x-icon name="folder" /><span>{{ $row['folder']->name }}</span><span class="muted">{{ $counts[$row['folder']->id] ?? 0 }}</span></a></li>
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
            @include('folders._fields', ['name' => $folder->name, 'parent' => $folder->parent_id, 'self' => $folder->id, 'matching' => $folder->bank_matching, 'locked' => $folder->is_default])
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
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('folders.cancel') }}</button>
                    <form method="post" action="{{ route('folders.destroy', $folder->id) }}">
                        @csrf
                        <button type="submit" class="button-danger">{{ __('folders.delete') }}</button>
                    </form>
                </div>
            </div>
        </div>
        @endunless
    @else
        <div class="col-head"><h1>{{ __('folders.new') }}</h1></div>
        <form method="post" action="{{ route('folders.store') }}">
            @csrf
            @include('folders._fields', ['name' => '', 'parent' => request('parent'), 'self' => null, 'matching' => false, 'locked' => false])
            <div class="actions"><button type="submit">{{ __('folders.create') }}</button></div>
        </form>
    @endif
@endsection
