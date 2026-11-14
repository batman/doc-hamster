{{-- Einstellungen → Eingang → E-Mail (point 153.5): placeholder until documents can come in by e-mail (point 30). --}}
@extends('layouts.settings')

@section('title', __('inbox.mail_title'))

@section('page')
    <div class="col-head"><h1>{{ __('inbox.mail_title') }}</h1></div>
    <section class="card">
        <h2 class="card-head"><x-icon name="mail" />{{ __('inbox.mail_title') }}</h2>
        <p class="card-text">{{ __('inbox.mail_soon') }}</p>
        <p class="card-text"><a href="{{ route('inbox.index', ['source' => 'mail']) }}">{{ __('inbox.title') }} → {{ __('inbox.sources.mail') }}</a></p>
    </section>
@endsection
