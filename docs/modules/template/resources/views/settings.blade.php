{{-- A module's settings page sits in Einstellungen → Module like every other page (layouts.settings). --}}
@extends('layouts.settings')

@section('title', __('m-beispiel::texts.title'))

@section('page')
    <section class="card">
        <h2 class="card-head"><x-icon name="puzzle" />{{ __('m-beispiel::texts.title') }}</h2>
        <p class="card-text">{{ __('m-beispiel::texts.count', ['documents' => $documents, 'notes' => $notes]) }}</p>
    </section>
@endsection
