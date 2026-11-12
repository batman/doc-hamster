{{-- The Papierkorb's properties (A165, A167), opened with the gear of its row: it may be renamed and coloured like a folder;
     it has no place in the tree, no symbol, no bank matching and can't be deleted – instead "Papierkorb leeren". --}}
@extends('layouts.app')

@section('title', $name)

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
        <x-window :title="$name" icon="trash" :url="route('folders.index', ['folder' => 'trash'])" :close="route('documents.index')">
            @if (session('status'))
                <p class="check-ok" role="status">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif
            <p><a href="{{ route('documents.trash') }}" data-window>{{ trans_choice('folders.used', $count, ['count' => $count]) }}</a></p>
            {{-- Saves on change like every properties module (A191-A194); without JavaScript the button posts. --}}
            <form method="post" action="{{ route('folders.trash') }}" data-autosave>
                @csrf
                <input type="hidden" name="look" value="1">
                <div class="field">
                    <label for="name-trash">{{ __('folders.name') }}</label>
                    <div class="field-addon">
                        <input id="name-trash" name="name" value="{{ old('name', $name) }}" maxlength="100" required>
                        <span class="addon-end"><button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('folders.save') }}" data-tooltip="{{ __('folders.save') }}" hidden><x-icon name="check" /></button></span>
                    </div>
                    <p class="field-hint">{{ __('folders.trash_note') }}</p>
                </div>
                <x-color-picker name="color" :value="old('color_hex') ?: old('color', $color ?? 'none')" :allow-none="true" :allow-own="true" key="trash" :legend="__('folders.color')" />
                <div class="actions">
                    <noscript><button type="submit">{{ __('folders.save') }}</button></noscript>
                    @if ($count > 0)
                        <button type="button" class="button-danger" data-dialog-open="trash-empty">{{ __('documents.bulk.empty') }}</button>
                    @endif
                </div>
            </form>
            @if ($count > 0)
                @include('documents._trash-empty')
            @endif
        </x-window>
    </div>
@endsection
