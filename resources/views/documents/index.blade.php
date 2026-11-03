@extends('layouts.app')

@section('title', __('documents.title'))

@section('content')
    <div class="page-head">
        <h1>{{ __('documents.title') }}</h1>
        <div class="toolbar">
            @if ($tags->isNotEmpty())
                {{-- Applies with every tick in the browser; the GET form is the fallback when the page doesn't hold every receipt. --}}
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
                        @foreach ($tags as $tag)
                            <label><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))> {{ $tag->name }}</label>
                        @endforeach
                        <button type="button" class="dropdown-reset" data-reset @if ($selected === []) hidden @endif>{{ __('tags.reset_filter') }}</button>
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
@endsection
