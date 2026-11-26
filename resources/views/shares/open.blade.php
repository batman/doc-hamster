{{-- Point 40, A103: a receipt handed out by link – for someone who is not signed in: look at it, or download it. --}}
@extends('layouts.app')

@section('title', $document->title)

@section('content')
    <h1>{{ $document->title }}</h1>
    <p>{{ __('shares.open_text', ['date' => $until->timezone(config('app.timezone'))->format('d.m.Y')]) }}</p>
    <div class="actions">
        @if ($inline)
            <a class="button" href="{{ route('shares.file', $token) }}"><x-icon name="eye" />{{ __('shares.open_view') }}</a>
        @endif
        <a class="button @if ($inline) button-quiet @endif" href="{{ route('shares.file', ['token' => $token, 'download' => 1]) }}"><x-icon name="download" />{{ __('shares.open_download') }}</a>
    </div>
@endsection
