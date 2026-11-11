@extends('layouts.settings')

@section('title', __('settings.title'))

@section('nav_footer')
    @php($ocr = app(\App\Infrastructure\Environment\Doctor::class)->isAvailable(\App\Infrastructure\Environment\Addon::Ocr))
    <p class="muted settings-addon">
        @if ($ocr)<x-icon name="circle-check" />@endif
        {{ __('settings.addons.ocr') }}: {{ $ocr ? __('settings.addons.available') : __('settings.addons.missing', ['install' => 'tesseract-ocr, tesseract-ocr-deu, ocrmypdf']) }}
    </p>
@endsection
