@extends('layouts.app')

@section('title', __('documents.trash'))

@section('content')
    <div class="page-head">
        <h1>{{ __('documents.trash') }}</h1>
        <a class="button button-quiet button-small" href="{{ route('documents.index') }}"><x-icon name="chevron-left" />{{ __('bank.back') }}</a>
    </div>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($documents->isEmpty())
        <p class="muted">{{ __('documents.trash_empty') }}</p>
    @else
        <ul class="view-list">
            @foreach ($documents as $document)
                <li>
                    @include('documents._type-icon')
                    <span>{{ $document->title }}@if ($document->in_print_tray) <span class="muted">({{ __('print_tray.title') }})</span>@endif</span>
                    <span class="muted">{{ __('documents.deleted_at', ['date' => $document->deleted_at?->format('d.m.Y H:i')]) }}</span>
                    <form method="post" action="{{ route('documents.restore', $document->id) }}">
                        @csrf
                        <button type="submit" class="button-quiet">{{ __('documents.restore') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
