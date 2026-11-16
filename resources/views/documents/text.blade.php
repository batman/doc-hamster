{{-- A text document in its own window (point 21): plain text in a text field, formatted text in a
     contenteditable area with a small toolbar (app.js "Text editor"). Saved with the button or Ctrl+S;
     without JavaScript the form posts as usual. People without documents.edit only read. --}}
@extends('layouts.app')

@section('title', $document->title)

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="$document->title" icon="file-text" kind="text" :url="route('documents.text', $document->id)" :close="route('documents.show', $document->id)" min="36rem">
        @if ($canEdit)
            <x-slot:actions>
                <div class="toolbar">
                    <span class="text-state muted" data-text-state data-saved="{{ __('documents.text.saved') }}" data-unsaved="{{ __('documents.text.unsaved') }}" data-saving="{{ __('documents.text.saving') }}" data-failed="{{ __('documents.text.failed') }}"></span>
                    <button type="submit" class="icon-button" form="text-form-{{ $document->id }}" aria-label="{{ __('documents.text.save') }} (Ctrl+S)" data-tooltip="{{ __('documents.text.save') }} (Ctrl+S)"><x-icon name="device-floppy" /></button>
                </div>
            </x-slot:actions>
        @endif

        @if (session('status'))
            <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
        @endif
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        @if ($canEdit)
            <form id="text-form-{{ $document->id }}" class="text-editor" method="post" action="{{ route('documents.text.update', $document->id) }}" data-text-editor data-leave="{{ __('documents.text.leave') }}">
                @csrf
                @if ($rich)
                    <div class="rt-toolbar" role="toolbar" aria-label="{{ __('documents.text.toolbar') }}" aria-controls="text-area-{{ $document->id }}">
                        <select class="input-small" data-rt-font aria-label="{{ __('documents.text.font') }}">
                            <option value="">{{ __('documents.text.font') }}</option>
                            @foreach (__('documents.text.fonts') as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <select class="input-small" data-rt-size aria-label="{{ __('documents.text.size') }}">
                            <option value="">{{ __('documents.text.size') }}</option>
                            @foreach (__('documents.text.sizes') as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="button-group" role="group">
                            @foreach (['bold' => 'bold', 'italic' => 'italic', 'underline' => 'underline'] as $command => $icon)
                                <button type="button" class="icon-button button-small" data-rt="{{ $command }}" aria-pressed="false" aria-label="{{ __('documents.text.'.$command) }}" data-tooltip="{{ __('documents.text.'.$command) }}"><x-icon :name="$icon" /></button>
                            @endforeach
                        </div>
                        <div class="button-group" role="group">
                            @foreach (['bullets' => 'list', 'numbers' => 'list-numbers', 'outdent' => 'indent-decrease', 'indent' => 'indent-increase'] as $command => $icon)
                                <button type="button" class="icon-button button-small" data-rt="{{ $command }}" aria-label="{{ __('documents.text.'.$command) }}" data-tooltip="{{ __('documents.text.'.$command) }}"><x-icon :name="$icon" /></button>
                            @endforeach
                        </div>
                        {{-- The alignment is a state of the text, one of four: an Umschalter (PO 2026-11-16), symbols with tooltips. --}}
                        <span class="switcher" role="group" aria-label="{{ __('documents.text.align') }}" data-switch-static>
                            @foreach (['left' => 'align-left', 'center' => 'align-center', 'right' => 'align-right', 'justify' => 'align-justified'] as $align => $icon)
                                <button type="button" data-rt="align" data-rt-value="{{ $align }}" aria-pressed="false" aria-label="{{ __('documents.text.align_'.$align) }}" data-tooltip="{{ __('documents.text.align_'.$align) }}"><x-icon :name="$icon" /></button>
                            @endforeach
                        </span>
                    </div>
                    <div id="text-area-{{ $document->id }}" class="rich-text rt-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="{{ $document->title }}" data-rt-area>{!! $content !!}</div>
                    <input type="hidden" name="content" value="" data-rt-content>
                @else
                    <textarea id="text-area-{{ $document->id }}" class="plain-text" name="content" aria-label="{{ $document->title }}" spellcheck="true" data-text-area>{{ $content }}</textarea>
                @endif
                <noscript><button type="submit">{{ __('documents.text.save') }}</button></noscript>
            </form>
        @else
            <div class="notice">{{ __('documents.text.read_only') }}</div>
            @if ($rich)
                <div class="rich-text">{!! $content !!}</div>
            @else
                <pre class="plain-text-view">{{ $content }}</pre>
            @endif
        @endif
    </x-window>
    </div>
@endsection
