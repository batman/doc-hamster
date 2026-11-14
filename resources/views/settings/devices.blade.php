{{-- Einstellungen → Geräte (points 152, A329, A330): upload keys for an iPhone shortcut or a program. The key shows once
     (Kopierfeld), the list shows name, kind, added, last sent; delete as row action with confirm. --}}
@extends('layouts.settings')

@section('title', __('devices.title'))

@php
    $header = $fresh !== null ? 'Bearer '.$fresh['key'] : '';
@endphp

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif

    @if ($fresh !== null)
        <section class="card">
            <h2 class="card-head"><x-icon name="lock" />{{ __('devices.key_title', ['name' => $fresh['name']]) }}</h2>
            <p class="card-text">{{ __('devices.key_once') }} {{ __('devices.key_scope') }}</p>
            @include('settings._device-copy', ['id' => 'device-url', 'label' => __('devices.url'), 'value' => $apiUrl])
            @include('settings._device-copy', ['id' => 'device-header', 'label' => __('devices.header'), 'value' => $header])
        </section>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="device-mobile" />{{ __('devices.list') }}
            <span class="card-action">
                <button type="button" class="button-small" data-dialog-open="add-device"><x-icon name="plus" />{{ __('devices.add') }}</button>
            </span>
        </h2>
        <ul class="pick-list">
            @forelse ($devices as $device)
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon :name="$device->kind === 'iphone' ? 'device-mobile' : 'device-desktop'" /></span>
                    <span>
                        <strong>{{ $device->name }}</strong>
                        <small>{{ __('devices.created_at', ['date' => $device->created_at->format('d.m.Y')]) }} · {{ __('devices.last_used', ['date' => $device->last_used_at?->format('d.m.Y H:i') ?? __('devices.never')]) }} · {{ __('devices.key_prefix', ['prefix' => $device->key_prefix]) }}</small>
                    </span>
                    <span class="pill">{{ __('devices.kinds.'.$device->kind) }}</span>
                    <span class="row-actions">
                        <button type="button" class="control-button" data-dialog-open="delete-device-{{ $device->id }}" aria-label="{{ __('devices.delete') }}: {{ $device->name }}" data-tooltip="{{ __('devices.delete') }}"><x-icon name="trash" /></button>
                    </span>
                </li>
            @empty
                <li class="list-empty">{{ __('devices.empty') }}</li>
            @endforelse
        </ul>
    </section>

    <div class="dialog-backdrop" data-dialog="add-device" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="add-device-title">
            <h2 id="add-device-title">{{ __('devices.add_title') }}</h2>
            <form method="post" action="{{ route('devices.store') }}">
                @csrf
                <div class="field">
                    <label for="device-name">{{ __('devices.name') }}</label>
                    <input id="device-name" name="name" maxlength="100" required placeholder="{{ __('devices.name_placeholder') }}">
                </div>
                <fieldset>
                    <legend>{{ __('devices.kind') }}</legend>
                    @include('documents._search-choice', ['name' => 'kind', 'value' => 'iphone', 'options' => __('devices.kinds'), 'toggle' => false, 'label' => __('devices.kind')])
                </fieldset>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('devices.cancel') }}</button>
                    <button type="submit"><x-icon name="plus" />{{ __('devices.add') }}</button>
                </div>
            </form>
        </div>
    </div>

    @foreach ($devices as $device)
        <div class="dialog-backdrop" data-dialog="delete-device-{{ $device->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-device-{{ $device->id }}-title">
                <h2 id="delete-device-{{ $device->id }}-title">{{ __('devices.delete_title') }}</h2>
                <p>{{ __('devices.delete_text') }}</p>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('devices.cancel') }}</button>
                    <form method="post" action="{{ route('devices.destroy', $device->id) }}">
                        @csrf
                        <button type="submit" class="button-danger">{{ __('devices.delete') }}</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach

    <x-fold-card fold="devices.iphone" :title="__('devices.iphone_title')" icon="device-mobile" :folded="$fresh !== null && $fresh['kind'] !== 'iphone'" :remember="$fresh === null">
        <ol class="card-text">
            @foreach (__('devices.iphone_steps') as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
        @include('settings._device-copy', ['id' => 'iphone-url', 'label' => __('devices.url'), 'value' => $apiUrl])
        @include('settings._device-copy', ['id' => 'iphone-header', 'label' => __('devices.header'), 'value' => $fresh !== null && $fresh['kind'] === 'iphone' ? $header : '', 'placeholder' => __('devices.key_placeholder')])
    </x-fold-card>

    <x-fold-card fold="devices.program" :title="__('devices.program_title')" icon="device-desktop" :folded="$fresh === null || $fresh['kind'] !== 'program'" :remember="$fresh === null">
        <ol class="card-text">
            @foreach (__('devices.program_steps') as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
        @include('settings._device-copy', ['id' => 'program-url', 'label' => __('devices.url'), 'value' => $apiUrl])
        @include('settings._device-copy', ['id' => 'program-header', 'label' => __('devices.header'), 'value' => $fresh !== null && $fresh['kind'] === 'program' ? $header : '', 'placeholder' => __('devices.key_placeholder')])
    </x-fold-card>
@endsection
