{{-- A page of the module as its own window (opened from the ⋯ menu of a document). --}}
@extends('layouts.app')

@section('title', __('m-beispiel::texts.action'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
        <x-window :title="__('m-beispiel::texts.action')" icon="puzzle" kind="m-beispiel" :close="route('documents.show', $document->id)">
            <section class="card">
                <h2 class="card-head"><x-icon name="file-text" />{{ $document->title }}</h2>
                <p class="card-text">{{ __('m-beispiel::texts.seen') }}</p>
            </section>
        </x-window>
    </div>
@endsection
