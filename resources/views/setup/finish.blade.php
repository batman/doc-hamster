@extends('setup.layout')

@section('content')
    <h1>{{ __('setup.wizard.finish_title') }}</h1>
    <p>{{ __('setup.wizard.finish_text') }}</p>
    <form method="post" action="{{ route('setup.finish.save') }}">
        @csrf
        <button type="submit">{{ __('setup.wizard.finish_submit') }}</button>
    </form>
@endsection
