@extends('layouts.app')

@section('title', __('tags.title'))

@section('content')
    <h1>{{ __('tags.title') }}</h1>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($confirm = session('confirm_delete'))
        <div class="notice" role="alert">
            <p><strong>{{ trans_choice('tags.confirm_delete', $confirm['count'], ['name' => $confirm['name'], 'count' => $confirm['count']]) }}</strong></p>
            <form method="post" action="{{ route('tags.destroy', $confirm['id']) }}">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <button type="submit">{{ __('tags.delete') }}</button>
            </form>
        </div>
    @endif

    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <ul class="documents">
        @foreach ($tags as $tag)
            <li>
                <form method="post" action="{{ route('tags.update', $tag->id) }}">
                    @csrf
                    <label for="name-{{ $tag->id }}">{{ __('tags.name') }}</label>
                    <input id="name-{{ $tag->id }}" name="name" value="{{ $tag->name }}" maxlength="64" required>
                    <p class="muted">{{ trans_choice('tags.used', $tag->documents_count, ['count' => $tag->documents_count]) }}</p>
                    <button type="submit">{{ __('tags.save') }}</button>
                    <button type="submit" formaction="{{ route('tags.destroy', $tag->id) }}" formnovalidate>{{ __('tags.delete') }}</button>
                </form>
            </li>
        @endforeach
    </ul>

    <h2>{{ __('tags.add') }}</h2>
    <form method="post" action="{{ route('tags.store') }}">
        @csrf
        <label for="name">{{ __('tags.name') }}</label>
        <input id="name" name="name" value="{{ old('name') }}" maxlength="64" required>
        <button type="submit">{{ __('tags.create') }}</button>
    </form>
@endsection
