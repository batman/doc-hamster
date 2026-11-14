{{-- Tags (design point 19, point 156): the window "Tags" with a search and the pills; a pill opens its editor as the next
     window, + opens "Neuer Tag". The editor window shows only for a tag, ?new=1, or a message after saving or deleting. --}}
@extends('layouts.settings')

@section('title', __('tags.title'))

@section('pane_actions')
    <a class="icon-button" href="{{ route('tags.index', ['new' => 1]) }}" data-window aria-label="{{ __('tags.new') }}" data-tooltip="{{ __('tags.new') }}"><x-icon name="plus" /></a>
@endsection

@section('pane')
    <label for="tag-search" class="visually-hidden">{{ __('tags.search') }}</label>
    <input id="tag-search" class="tag-search" type="search" placeholder="{{ __('tags.search') }}" data-tag-search>
    <ul class="tags">
        @foreach ($tags as $entry)
            <li data-tag-name="{{ mb_strtolower($entry->name) }}"><a class="tag tag-{{ $entry->id }} @if ($tag?->id === $entry->id) is-selected @endif" href="{{ route('tags.index', ['tag' => $entry->id]) }}" data-window>{{ $entry->name }}</a></li>
        @endforeach
    </ul>
@endsection

@if ($tag || request()->boolean('new') || session('status') || session('confirm_delete') || $errors->any())
@section('page_title', $tag?->name ?? __('tags.new'))

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
        <p><span class="tag tag-{{ $tag->id }} is-selected">{{ $tag->name }}</span></p>
        <p><a href="{{ route('documents.index', ['tags' => [$tag->id]]) }}">{{ trans_choice('tags.used', $tag->documents_count, ['count' => $tag->documents_count]) }}</a></p>
        {{-- Point 170.2: the tag saves on change (FRAMEWORK.md "Properties module"); without JavaScript the button posts. --}}
        <form method="post" action="{{ route('tags.update', $tag->id) }}" data-autosave>
            @csrf
            @include('tags._fields', ['name' => $tag->name, 'color' => $tag->color, 'autosave' => true])
            <div class="actions">
                <noscript><button type="submit">{{ __('tags.save') }}</button></noscript>
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
        <form method="post" action="{{ route('tags.store') }}">
            @csrf
            @include('tags._fields', ['name' => '', 'color' => null])
            <div class="actions"><button type="submit">{{ __('tags.create') }}</button></div>
        </form>
    @endif
@endsection
@endif
