@extends('layouts.settings')

@section('title', __('settings.title'))

@section('nav_footer')
    @php($ocrService = app(\App\Infrastructure\Environment\LocalOcr::class))
    @php($ocrMode = $ocrService->mode())
    @php($ocrStatus = $ocrService->status())
    <p class="muted settings-addon">
        @if (in_array($ocrMode, ['system', 'app'], true))<x-icon name="circle-check" />@endif
        {{ __('settings.addons.ocr') }}:
        @if ($ocrMode === 'system' || $ocrMode === 'app')
            {{ __('settings.addons.available_'.$ocrMode) }}
        @elseif (($ocrStatus['state'] ?? null) === 'running')
            {{ __('settings.addons.running') }}
        @elseif (($ocrStatus['state'] ?? null) === 'failed')
            {{ __('settings.addons.broken', ['reason' => $ocrStatus['reason'] ?? '?']) }}
            {{ $ocrMode === 'browser' ? __('settings.addons.browser') : __('settings.addons.browser_missing') }}
        @else
            {{ __('settings.addons.not_set_up') }}
        @endif
    </p>
    @if (! in_array($ocrMode, ['system', 'app'], true))
        @can('settings.manage')
            <form method="post" action="{{ route('ocr.setup') }}" class="settings-addon">
                @csrf
                <button type="submit" class="button-quiet button-small">{{ __('settings.addons.set_up') }}</button>
            </form>
        @endcan
    @endif
@endsection
