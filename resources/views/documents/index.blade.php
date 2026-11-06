@extends('layouts.app')

@section('title', $open?->title ?? __('documents.title'))

@section('main_class', 'page page-split')

@section('content')
    <div class="split-main">
        @if ($folders !== [] || $folder !== null)
            {{-- Folders (issue #77): top level and the open branch; a folder shows everything below it. --}}
            <ul class="folder-bar" aria-label="{{ __('folders.title') }}">
                <li><a href="{{ route('documents.index') }}" @class(['is-selected' => $folder === null])><x-icon name="folder" />{{ __('folders.all') }}</a></li>
                @foreach ($folders as $row)
                    <li><a href="{{ route('documents.index', ['folder' => $row['folder']->id]) }}" @class(['is-selected' => $folder === $row['folder']->id])>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</a></li>
                @endforeach
            </ul>
        @endif
        <div class="page-head">
            <h1>{{ __('documents.title') }}</h1>
            <div class="toolbar">
                @if ($trashed > 0)
                    <a href="{{ route('documents.trash') }}">{{ __('documents.trash_count', ['count' => $trashed]) }}</a>
                @endif
                @if ($tags->isNotEmpty())
                    {{-- Ticks only collect; "Anwenden" filters, in the browser when the page holds every receipt, else on the server. --}}
                    <form class="dropdown" data-kind="filter" data-complete="{{ $complete ? '1' : '0' }}" method="get" action="{{ route('documents.index') }}">
                        @if ($month)
                            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                        @endif
                        <button type="button" class="icon-button @if ($selected !== []) is-active @endif" data-tooltip="{{ __('documents.filter') }}" aria-label="{{ __('documents.filter') }}" aria-haspopup="true" aria-expanded="false">
                            <span data-icon="off" @if ($selected !== []) hidden @endif><x-icon name="filter" /></span>
                            <span data-icon="on" @if ($selected === []) hidden @endif><x-icon name="filter-check" /></span>
                            <span class="badge" @if ($selected === []) hidden @endif>{{ count($selected) }}</span>
                        </button>
                        <div class="dropdown-panel" hidden>
                            <p class="dropdown-title">{{ __('tags.filter') }}</p>
                            <div class="tags dropdown-tags">
                                @foreach ($tags as $tag)
                                    <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
                                @endforeach
                            </div>
                            <div class="dialog-actions">
                                <button type="button" class="button-quiet" data-reset>{{ __('documents.reset') }}</button>
                                <button type="submit" data-apply>{{ __('documents.apply') }}</button>
                            </div>
                        </div>
                    </form>
                @endif
                <form class="dropdown" data-kind="view" method="post" action="{{ route('documents.view') }}">
                    @csrf
                    <button type="button" class="icon-button" data-tooltip="{{ __('documents.view') }}" aria-label="{{ __('documents.view') }}" aria-haspopup="true" aria-expanded="false"><x-icon :name="$views[$view]" /></button>
                    <div class="dropdown-panel" role="menu" hidden>
                        <p class="dropdown-title">{{ __('documents.view') }}</p>
                        @foreach ($views as $name => $icon)
                            <button type="submit" name="view" value="{{ $name }}" class="menu-item" role="menuitemradio" aria-checked="{{ $name === $view ? 'true' : 'false' }}"><x-icon :name="$icon" /><span>{{ __('documents.views.'.$name) }}</span></button>
                        @endforeach
                    </div>
                </form>
            </div>
        </div>

        @if (session('status'))
            <p class="check-ok" role="status">{{ session('status') }}</p>
        @endif

        @if ($documents->isEmpty() && $selected === [] && $view !== 'calendar')
            <div class="empty-state"><p class="muted">{{ __('documents.empty') }}</p></div>
        @else
            @include('documents.views.'.(in_array($view, ['small', 'large'], true) ? 'tiles' : $view))
            <p class="muted" data-no-match @unless ($documents->isEmpty() && $selected !== []) hidden @endunless>{{ __('tags.no_match') }}</p>
        @endif
    </div>

    @if ($open)
        @include('documents._detail', ['document' => $open])
    @endif
@endsection
