{{-- Tags (design point 19): pills with a search on the left, the editor on the right. --}}
@extends('layouts.settings')

@section('title', __('tags.title'))

@section('pane')
    <div class="col-head">
        <h2>{{ __('tags.title') }}</h2>
        <button type="button" class="icon-button" data-fold="1" aria-label="{{ __('settings.fold') }}" data-tooltip="{{ __('settings.fold') }}"><x-icon name="chevron-left" /></button>
    </div>
    <label for="tag-search" class="visually-hidden">{{ __('tags.search') }}</label>
    <input id="tag-search" class="col-search" type="search" placeholder="{{ __('tags.search') }}" data-tag-search>
    <ul class="tags">
        @foreach ($tags as $entry)
            <li data-tag-name="{{ mb_strtolower($entry->name) }}"><a class="tag tag-{{ $entry->id }} @if ($tag?->id === $entry->id) is-selected @endif" href="{{ route('tags.index', ['tag' => $entry->id]) }}">{{ $entry->name }}</a></li>
        @endforeach
    </ul>
@endsection

@section('page')
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif
    @if ($confirm = session('confirm_delete'))
        <div class="notice" role="alert">
            <p><strong>{{ trans_choice('tags.confirm_delete', $confirm['count'], ['name' => $confirm['name'], 'count' => $confirm['count']]) }}</strong></p>
            <form method="post" action="{{ route('tags.destroy', $confirm['id']) }}">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <button type="submit" class="button-danger">{{ __('tags.delete') }}</button>
            </form>
        </div>
    @endif

    @if ($tag)
        <div class="col-head">
            <h1><span class="tag tag-{{ $tag->id }} is-selected">{{ $tag->name }}</span></h1>
            <a class="icon-button" href="{{ route('tags.index') }}" aria-label="{{ __('documents.close') }}" data-tooltip="{{ __('documents.close') }}"><x-icon name="x" /></a>
        </div>
        <p><a href="{{ route('documents.index', ['tags' => [$tag->id]]) }}">{{ trans_choice('tags.used', $tag->documents_count, ['count' => $tag->documents_count]) }}</a></p>
        <form method="post" action="{{ route('tags.update', $tag->id) }}">
            @csrf
            @include('tags._fields', ['name' => $tag->name, 'color' => $tag->color])
            <div class="actions">
                <button type="submit">{{ __('tags.save') }}</button>
                <button type="button" class="button-danger" data-dialog-open="delete-tag">{{ __('tags.delete') }}</button>
            </div>
        </form>

        <div class="dialog-backdrop" data-dialog="delete-tag" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-tag-title">
                <h2 id="delete-tag-title">{{ __('tags.delete_title') }}</h2>
                <p>{{ __('tags.delete_text', ['name' => $tag->name]) }}</p>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('tags.cancel') }}</button>
                    <form method="post" action="{{ route('tags.destroy', $tag->id) }}">
                        @csrf
                        <input type="hidden" name="confirmed" value="1">
                        <button type="submit" class="button-danger">{{ __('tags.delete') }}</button>
                    </form>
                </div>
            </div>
        </div>
    @else
        <div class="col-head"><h1>{{ __('tags.new') }}</h1></div>
        <form method="post" action="{{ route('tags.store') }}">
            @csrf
            @include('tags._fields', ['name' => '', 'color' => null])
            <div class="actions"><button type="submit">{{ __('tags.create') }}</button></div>
        </form>
    @endif
@endsection
