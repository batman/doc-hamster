{{-- The Papierkorb's properties (A165, A167), opened with the gear of its row: it may be renamed and coloured like a folder;
     it has no place in the tree, no symbol, no bank matching and can't be deleted – instead "Papierkorb leeren". --}}
@extends('layouts.app')

@section('title', $name)

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
        {{-- PO 2026-11-16: the name is changed by a click on the title (Feld mit ✓); its colour stays. --}}
        <x-window :title="$name" icon="trash" :url="route('folders.index', ['folder' => 'trash'])" :close="route('documents.index')" :rename-url="route('folders.trash')">
            @if (session('status'))
                <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
            @endif
            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif
            <p><a href="{{ route('documents.trash') }}" data-window>{{ trans_choice('folders.used', $count, ['count' => $count]) }}</a></p>
            {{-- Saves on change like every properties module (A191-A194); without JavaScript the button posts. --}}
            <form method="post" action="{{ route('folders.trash') }}" data-autosave>
                @csrf
                <input type="hidden" name="look" value="1">
                <input type="hidden" name="name" value="{{ old('name', $name) }}">
                <p class="field-hint">{{ __('folders.trash_note') }}</p>
                <x-color-picker name="color" :value="old('color_hex') ?: old('color', $color ?? 'none')" :allow-none="true" :allow-own="true" key="trash" :legend="__('folders.color')" />
                <div class="actions">
                    <noscript><button type="submit">{{ __('folders.save') }}</button></noscript>
                    @if ($count > 0)
                        <button type="button" class="button-danger" data-dialog-open="trash-empty">{{ __('documents.bulk.empty') }}</button>
                    @endif
                </div>
            </form>
            @can('settings.manage')
                {{-- Point 207: organisation-wide, each choice saves at once (Optionskarten). --}}
                <form method="post" action="{{ route('documents.trash.settings') }}" data-autosave>
                    @csrf
                    @foreach (['keep' => \App\Domain\Documents\TrashSettings::KEEP, 'undo' => \App\Domain\Documents\TrashSettings::UNDO] as $setting => $choices)
                        <fieldset class="pick-list option-cards">
                            <legend>{{ __('documents.trash_settings.'.$setting) }}</legend>
                            @foreach ($choices as $choice)
                                <label class="pick-row">
                                    <input type="radio" class="visually-hidden" name="{{ $setting }}" value="{{ $choice }}" @checked($trashSettings[$setting] === $choice)>
                                    <span><strong>{{ __('documents.trash_settings.'.$setting.'_options.'.$choice.'.title') }}</strong><small>{{ __('documents.trash_settings.'.$setting.'_options.'.$choice.'.text') }}</small></span>
                                    <span class="pick-mark"><x-icon name="check" /></span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endforeach
                    <p class="field-hint">{{ __('documents.trash_settings.hint') }}</p>
                    <noscript><button type="submit">{{ __('folders.save') }}</button></noscript>
                </form>
            @endcan
            @if ($count > 0)
                @include('documents._trash-empty')
            @endif
        </x-window>
    </div>
@endsection
