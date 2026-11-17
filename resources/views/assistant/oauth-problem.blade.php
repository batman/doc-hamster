{{-- An assistant's sign-in that cannot go on (D-040): unknown assistant, a wrong return address, or the door closed. --}}
@extends('layouts.app')

@section('title', __('assistant.consent.title'))

@section('content')
    <h1>{{ __('assistant.problem') }}</h1>
    <div class="notice">{{ $message }}</div>
@endsection
