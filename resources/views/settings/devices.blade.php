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
        {{-- Point 201.2: the key at once in an open dialog (no flash), with copy fields and "Verbindung testen". --}}
        <div class="dialog-backdrop" data-dialog="device-key" data-device-key>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="device-key-title">
                <h2 id="device-key-title">{{ __('devices.key_title', ['name' => $fresh['name']]) }}</h2>
                <p class="card-text"><strong>{{ __('devices.key_once') }}</strong> {{ __('devices.key_scope') }}</p>
                @include('settings._device-copy', ['id' => 'device-url', 'label' => __('devices.url'), 'value' => $apiUrl])
                @include('settings._device-copy', ['id' => 'device-header', 'label' => __('devices.header'), 'value' => $header])
                <p class="device-test-result" data-device-test-result role="status" aria-live="polite"></p>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-device-test="device-header" data-url="{{ route('api.upload.check') }}"
                        data-running="{{ __('devices.test_running') }}" data-msg401="{{ __('devices.test_401') }}" data-msg403="{{ __('devices.test_403') }}"
                        data-msg-other="{{ __('devices.test_other', ['status' => ':status']) }}" data-msg-failed="{{ __('devices.test_failed') }}"><x-icon name="plug" />{{ __('devices.test') }}</button>
                </div>
            </div>
        </div>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="device-mobile" />{{ __('devices.list') }}
            <span class="card-action">
                <button type="button" class="button-small" data-dialog-open="add-device"><x-icon name="plus" />{{ __('devices.add') }}</button>
            </span>
        </h2>
        {{-- A348: whoever sets up a phone finds the phone test right here. --}}
        <p class="card-note"><a href="{{ route('phone-check.qr') }}" data-window><x-icon name="stethoscope" /> {{ __('devices.phone_check_first') }}</a></p>
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
                        <button type="button" class="control-button" data-dialog-open="rekey-device-{{ $device->id }}" aria-label="{{ __('devices.rekey') }}: {{ $device->name }}" data-tooltip="{{ __('devices.rekey') }}"><x-icon name="refresh" /></button>
                        <button type="button" class="control-button control-danger" data-dialog-open="delete-device-{{ $device->id }}" aria-label="{{ __('devices.delete') }}: {{ $device->name }}" data-tooltip="{{ __('devices.delete') }}"><x-icon name="trash" /></button>
                    </span>
                </li>
            @empty
                <x-empty tag="li" icon="device-mobile" :text="__('empty.devices')" />
            @endforelse
        </ul>
    </section>

    <div class="dialog-backdrop" data-dialog="add-device" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="add-device-title">
            <h2 id="add-device-title">{{ __('devices.add_title') }}</h2>
            <form method="post" action="{{ route('devices.store') }}" data-confirm-sudo>
                @csrf
                <div class="field">
                    <label for="device-name">{{ __('devices.name') }}</label>
                    <input id="device-name" name="name" maxlength="100" required placeholder="{{ __('devices.name_placeholder') }}">
                </div>
                <fieldset>
                    <legend>{{ __('devices.kind') }}</legend>
                    @include('documents._search-choice', ['name' => 'kind', 'value' => 'iphone', 'options' => __('devices.kinds'), 'icons' => ['iphone' => 'device-mobile', 'program' => 'device-desktop'], 'toggle' => false, 'label' => __('devices.kind')])
                </fieldset>
                <div class="dialog-actions">
                    <button type="submit"><x-icon name="plus" />{{ __('devices.add') }}</button>
                </div>
            </form>
        </div>
    </div>

    @foreach ($devices as $device)
        <div class="dialog-backdrop" data-dialog="rekey-device-{{ $device->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="rekey-device-{{ $device->id }}-title">
                <h2 id="rekey-device-{{ $device->id }}-title">{{ __('devices.rekey_title') }}</h2>
                <p>{{ __('devices.rekey_text') }}</p>
                <div class="dialog-actions">
                    <form method="post" action="{{ route('devices.rekey', $device->id) }}" data-confirm-sudo>
                        @csrf
                        <button type="submit"><x-icon name="refresh" />{{ __('devices.rekey') }}</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="dialog-backdrop" data-dialog="delete-device-{{ $device->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-device-{{ $device->id }}-title">
                <h2 id="delete-device-{{ $device->id }}-title">{{ __('devices.delete_title') }}</h2>
                <p>{{ __('devices.delete_text') }}</p>
                <div class="dialog-actions">
                    <form method="post" action="{{ route('devices.destroy', $device->id) }}" data-confirm-sudo>
                        @csrf
                        <button type="submit" class="button-danger">{{ __('devices.delete') }}</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach

    {{-- Point 202:the ready-made shortcut (QR + link) and its four steps. --}}
    <x-fold-card fold="devices.shortcut" :title="__('devices.shortcut_title')" icon="device-mobile">
        <div class="device-shortcut">
            <figure class="qr-card"><img src="{{ $shortcutQr }}" width="180" height="180" alt="{{ __('devices.shortcut_qr') }}"><figcaption>{{ __('devices.shortcut_qr') }}</figcaption></figure>
            <div>
                <a class="button button-small" href="{{ $shortcutUrl }}" target="_blank" rel="noopener"><x-icon name="download" />{{ __('devices.shortcut_load') }}</a>
                <ol class="card-text">
                    @foreach (__('devices.shortcut_steps') as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </div>
        </div>
        @include('settings._device-copy', ['id' => 'iphone-url', 'label' => __('devices.url'), 'value' => $apiUrl])
        @include('settings._device-copy', ['id' => 'iphone-header', 'label' => __('devices.header'), 'value' => $fresh !== null && $fresh['kind'] === 'iphone' ? $header : '', 'placeholder' => __('devices.key_placeholder')])
        {{-- Point 201.4: where the value comes from. --}}
        <p class="field-hint">{{ __('devices.header_hint') }}</p>
    </x-fold-card>

    {{-- Point 204 (A37): the second shortcut, "Foto aufnehmen" from the Home screen – photo, done. --}}
    <x-fold-card fold="devices.photo" :title="__('devices.photo_title')" icon="photo">
        <div class="device-shortcut">
            @if ($photoUrl !== null)
                <figure class="qr-card"><img src="{{ $photoQr }}" width="180" height="180" alt="{{ __('devices.shortcut_qr') }}"><figcaption>{{ __('devices.shortcut_qr') }}</figcaption></figure>
            @endif
            <div>
                @if ($photoUrl !== null)
                    <a class="button button-small" href="{{ $photoUrl }}" target="_blank" rel="noopener"><x-icon name="download" />{{ __('devices.shortcut_load') }}</a>
                @else
                    <p class="card-note">{{ __('devices.photo_soon') }}</p>
                @endif
                <ol class="card-text">
                    @foreach (__('devices.photo_steps') as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </div>
        </div>
    </x-fold-card>

    {{-- Point 203: building it by hand, the traps shown as wrong → right. --}}
    <x-fold-card fold="devices.manual" :title="__('devices.manual_title')" icon="tools" :folded="true">
        <p class="card-text">{{ __('devices.manual_intro') }}</p>
        <ol class="device-manual">
            @foreach (__('devices.manual_steps') as $step)
                <li>
                    <span>{{ $step['text'] }}</span>
                    <span class="device-compare">
                        <span class="pill pill-error"><x-icon name="x" /><span class="visually-hidden">{{ __('devices.wrong') }}:</span> {{ $step['wrong'] }}</span>
                        <span aria-hidden="true">→</span>
                        <span class="pill pill-ok"><x-icon name="check" /><span class="visually-hidden">{{ __('devices.right') }}:</span> {{ $step['right'] }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
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
