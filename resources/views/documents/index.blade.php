@extends('layouts.app')

@section('title', __('documents.title'))

@section('content')
    <h1>{{ __('documents.title') }}</h1>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @can('documents.upload')
        <form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data">
            @csrf
            <label for="file">{{ __('documents.choose') }}</label>
            <input id="file" type="file" name="file" accept="image/*,application/pdf" required>
            @error('file')<p class="error" role="alert">{{ $message }}</p>@enderror
            @if ($tags->isNotEmpty())
                <fieldset class="rights">
                    <legend>{{ __('tags.choose') }}</legend>
                    @include('documents._tag-chips', ['selected' => []])
                </fieldset>
            @endif
            <button type="submit">{{ __('documents.upload') }}</button>
        </form>
    @endcan

    @if ($tags->isNotEmpty())
        <form method="get" action="{{ url('/') }}">
            <fieldset class="rights">
                <legend>{{ __('tags.filter') }}</legend>
                @include('documents._tag-chips')
            </fieldset>
            <button type="submit">{{ __('tags.apply_filter') }}</button>
            @if ($selected !== [])
                <a href="{{ url('/') }}">{{ __('tags.reset_filter') }}</a>
            @endif
        </form>
    @endif

    @if ($documents->isEmpty())
        <p class="muted">{{ $selected !== [] ? __('tags.no_match') : __('documents.empty') }}</p>
    @else
        <ul class="documents">
            @foreach ($documents as $document)
                @php($file = $document->files->first())
                <li>
                    @if ($file)
                        <a href="{{ route('documents.file', $file->id) }}">
                            @if ($file->isImage())
                                <img src="{{ route('documents.file', $file->id) }}" alt="" loading="lazy">
                            @endif
                            {{ $document->title }}
                        </a>
                    @else
                        {{ $document->title }}
                    @endif
                    <span class="muted">{{ $document->created_at->format('d.m.Y H:i') }}@if ($document->tags->isNotEmpty()) · {{ $document->tags->pluck('name')->implode(', ') }}@endif</span>
                    @can('documents.edit')
                        @if ($tags->isNotEmpty())
                            <details>
                                <summary>{{ __('tags.change') }}</summary>
                                <form method="post" action="{{ route('documents.tags', $document->id) }}">
                                    @csrf
                                    @include('documents._tag-chips', ['selected' => $document->tags->pluck('id')->all()])
                                    <button type="submit">{{ __('tags.change') }}</button>
                                </form>
                            </details>
                        @endif
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
@endsection
