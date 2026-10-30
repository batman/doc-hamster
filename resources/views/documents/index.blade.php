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
            <button type="submit">{{ __('documents.upload') }}</button>
        </form>
    @endcan

    @if ($documents->isEmpty())
        <p class="muted">{{ __('documents.empty') }}</p>
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
                    <span class="muted">{{ $document->created_at->format('d.m.Y H:i') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
