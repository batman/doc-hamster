{{-- Einstellungen → Farben (point 15): the shared palette as a sortable list. "+ Farbe hinzufügen" at the top (point 221,
     Aufklapp-Plus row); in a row the colour circle saves on pick, the name on Enter or ✓ (A191-A194), delete is a row
     action. --}}
@extends('layouts.settings')

@section('title', __('palette.title'))

@section('page')
    <p class="muted">{{ __('palette.intro') }}</p>
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    @php $adding = $errors->any() && old('name') !== null; @endphp
    <form method="post" action="{{ route('palette.store') }}" class="expand-plus is-row @if ($adding) is-open is-filled @endif" data-expand-plus>
        @csrf
        <button type="button" class="list-entry expand-plus-open"><x-icon name="plus" /><span>{{ __('palette.add') }}</span></button>
        <div class="pick-row is-static expand-plus-row">
            <x-color-swatch name="hex" :value="old('hex', '#ff7622')" :label="__('palette.hex')" />
            <div class="field-addon expand-plus-field">
                <input class="input-small" name="name" maxlength="60" value="{{ old('name') }}" placeholder="{{ __('palette.new_name') }}" aria-label="{{ __('palette.name') }}" autocomplete="off">
                <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="{{ __('palette.create') }}" data-tooltip="{{ __('palette.create') }}" @unless ($adding) hidden @endunless><x-icon name="check" /></button></span>
            </div>
        </div>
    </form>

    @if ($colors->isEmpty())
        <x-empty icon="palette" :text="__('palette.empty')" />
    @else
        <ul class="pick-list palette-rows" data-sortable data-palette-order="{{ route('palette.order') }}" data-order-failed="{{ __('palette.order_invalid') }}">
            @foreach ($colors as $color)
                <li class="pick-row is-static" data-id="{{ $color->id }}">
                    <button type="button" class="pick-handle" aria-label="{{ __('palette.drag', ['name' => $color->name]) }}"><x-icon name="grip-vertical" /></button>
                    {{-- PO 2026-11-16: the colour as a Farbkreis (our dialog, saves at once), the name by click (Feld mit ✓). --}}
                    <form method="post" action="{{ route('palette.update', $color->id) }}" class="palette-edit" data-autosave>
                        @csrf
                        <x-color-swatch name="hex" :value="$color->hex" :label="__('palette.hex')" />
                    </form>
                    <span><strong><x-edit-in-place name="name" :value="$color->name" :action="route('palette.update', $color->id)" :label="__('palette.name')" maxlength="60" /></strong></span>
                    <span class="row-actions">
                        <form method="post" action="{{ route('palette.destroy', $color->id) }}">
                            @csrf
                            <button type="submit" class="control-button control-danger" aria-label="{{ __('palette.delete') }}: {{ $color->name }}" data-tooltip="{{ __('palette.delete') }}"><x-icon name="trash" /></button>
                        </form>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
