{{-- Einstellungen → Farben (point 15): the shared palette as a sortable list; each row renames, recolours or deletes. --}}
@extends('layouts.settings')

@section('title', __('palette.title'))

@section('page')
    <div class="col-head"><h1>{{ __('palette.title') }}</h1></div>
    <p class="muted">{{ __('palette.intro') }}</p>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    @if ($colors->isEmpty())
        <p class="muted">{{ __('palette.empty') }}</p>
    @else
        <ul class="pick-list palette-rows" data-sortable data-palette-order="{{ route('palette.order') }}" data-order-failed="{{ __('palette.order_invalid') }}">
            @foreach ($colors as $color)
                <li class="pick-row is-static palette-row" data-id="{{ $color->id }}">
                    <button type="button" class="pick-handle" aria-label="{{ __('palette.drag', ['name' => $color->name]) }}"><x-icon name="grip-vertical" /></button>
                    <form method="post" action="{{ route('palette.update', $color->id) }}" class="palette-edit">
                        @csrf
                        <label for="hex-{{ $color->id }}" class="visually-hidden">{{ __('palette.hex') }}</label>
                        <input id="hex-{{ $color->id }}" type="color" name="hex" value="{{ $color->hex }}">
                        <label for="name-{{ $color->id }}" class="visually-hidden">{{ __('palette.name') }}</label>
                        <input id="name-{{ $color->id }}" name="name" value="{{ $color->name }}" maxlength="60" required>
                        <button type="submit" class="icon-button" aria-label="{{ __('palette.save') }}" data-tooltip="{{ __('palette.save') }}"><x-icon name="check" /></button>
                    </form>
                    <form method="post" action="{{ route('palette.destroy', $color->id) }}">
                        @csrf
                        <button type="submit" class="icon-button" aria-label="{{ __('palette.delete') }}" data-tooltip="{{ __('palette.delete') }}"><x-icon name="trash" /></button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <form method="post" action="{{ route('palette.store') }}" class="palette-edit palette-add">
        @csrf
        <label for="hex-new" class="visually-hidden">{{ __('palette.hex') }}</label>
        <input id="hex-new" type="color" name="hex" value="#ff7622">
        <label for="name-new" class="visually-hidden">{{ __('palette.name') }}</label>
        <input id="name-new" name="name" maxlength="60" required placeholder="{{ __('palette.name') }}">
        <button type="submit"><x-icon name="plus" /> {{ __('palette.add') }}</button>
    </form>
@endsection
