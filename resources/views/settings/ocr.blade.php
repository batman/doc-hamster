{{-- Einstellungen → Texterkennung (points 35/43/61, A21/A24): the content lives in settings/_ocr (also shown as a
     module of its own from the OCR widget, point 123); the log is Einstellungen → Log, logbook "Texterkennung" (point 116). --}}
@extends('layouts.settings')

@section('title', __('ocr.title'))

@section('page')
    @include('settings._ocr')
@endsection
