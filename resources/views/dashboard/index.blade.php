{{-- Home (points 176, A349-A353): one dashboard window of widgets, laid out per person. The pencil switches the edit
     mode: "+" adds a widget, each widget shows grip (drag to reorder), size S/M/L and X; every change saves at once.
     Outside the edit mode nothing moves. The grid follows the window width (container "win"). JS: modules/dashboard.js. --}}
@extends('layouts.app')

@section('title', __('dashboard.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('dashboard.title')" icon="home" kind="dashboard" :url="route('home')" :close="route('home')">
        <x-slot:actions>
            <div class="toolbar">
                <div class="dropdown" data-dash-add hidden>
                    <button type="button" class="icon-button" aria-label="{{ __('dashboard.add') }}" data-tooltip="{{ __('dashboard.add') }}" aria-haspopup="true" aria-expanded="false"><x-icon name="plus" /></button>
                    <div class="dropdown-panel" role="menu" hidden>
                        @foreach (\App\Domain\Dashboard\DashboardLayout::WIDGETS as $type => $meta)
                            @if (in_array($type, $available, true))
                                <button type="button" class="menu-item" role="menuitem" data-dash-add-type="{{ $type }}" @unless (in_array($type, $addable, true)) hidden @endunless><x-icon :name="$meta['icon']" /><span>{{ __('dashboard.widgets.'.$type) }}</span></button>
                            @endif
                        @endforeach
                        <span class="menu-item is-disabled" data-dash-add-none @if ($addable !== []) hidden @endif><x-icon name="check" /><span>{{ __('dashboard.add_none') }}</span></span>
                    </div>
                </div>
                <button type="button" class="icon-button" data-dash-edit aria-pressed="false" aria-label="{{ __('dashboard.edit') }}" data-tooltip="{{ __('dashboard.edit') }}" data-label-edit="{{ __('dashboard.edit') }}" data-label-done="{{ __('dashboard.edit_done') }}"><x-icon name="pencil" /></button>
            </div>
        </x-slot:actions>

        <div class="dash" data-dash data-layout="{{ route('dashboard.layout') }}" data-add="{{ route('dashboard.widgets.store') }}"
             data-unpin="{{ route('dashboard.pins.destroy') }}" data-photo="{{ route('dashboard.photo.store') }}" data-photo-delete="{{ route('dashboard.photo.destroy') }}"
             @can('documents.upload') data-upload="{{ route('documents.store') }}" @endcan
             data-failed="{{ __('dashboard.failed') }}">
            @if (session('status'))
                <p class="check-ok" role="status">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif
            <ul class="dash-grid" data-dash-grid>
                @foreach ($widgets as $widget)
                    @include('dashboard._widget', ['widget' => $widget])
                @endforeach
            </ul>
        </div>
    </x-window>
    </div>
@endsection
