@extends('layouts.app')

@section('title', __('errors.'.$code.'.title'))

@section('content')
    <div class="error-page">
        <p class="error-code">{{ $code }}</p>
        <h1>{{ __('errors.'.$code.'.title') }}</h1>
        <p>{{ __('errors.'.$code.'.text') }}</p>
        <a class="button" href="{{ url('/') }}">{{ __('errors.home') }}</a>
    </div>
@endsection
