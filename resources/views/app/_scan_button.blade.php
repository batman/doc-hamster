{{-- One "scan a page" button: with the camera a photo input (app.js sends it as the next page),
     with the printer a scan request. $big: the large first-choice tile. --}}
@php($label = $big ? __('app.scan.'.$choice) : __('app.scan.next'))
@if ($with === 'camera')
    <form class="scan-photo" method="post" action="{{ route('scan.photo') }}" enctype="multipart/form-data" data-scan-photo data-busy="{{ __('app.scan.adding') }}" data-unreachable="{{ __('app.scan.unreachable') }}" data-offline="{{ __('app.shell.offline_title') }}">
        @csrf
        <input type="hidden" name="app_back" value="{{ $here($choice) }}">
        <label class="{{ $big ? 'scan-big' : 'phone-action phone-wide' }}">
            <input class="visually-hidden" type="file" name="photo" accept="image/*" capture="environment">
            <x-icon :name="$icon" /><span>{{ $label }}</span>
            @if ($big)<small>{{ __('app.scan.'.$choice.'_camera_hint') }}</small>@endif
        </label>
        <noscript><button type="submit">{{ __('app.scan.save') }}</button></noscript>
    </form>
@else
    <form method="post" action="{{ route('scan.page') }}" data-busy="{{ __('scan.scanning') }}">
        @csrf
        <input type="hidden" name="app_back" value="{{ $here($choice) }}">
        <input type="hidden" name="folder" value="{{ $folder }}">
        <input type="hidden" name="source" value="{{ $choice === 'many' && $feeder ? 'feeder' : 'platen' }}">
        <input type="hidden" name="color" value="color">
        <input type="hidden" name="dpi" value="{{ $dpi }}">
        <button type="submit" class="{{ $big ? 'scan-big' : 'phone-action phone-wide' }}"><x-icon :name="$icon" /><span>{{ $label }}</span>
            @if ($big)<small>{{ __('app.scan.'.$choice.'_hint') }}</small>@endif
        </button>
    </form>
@endif
