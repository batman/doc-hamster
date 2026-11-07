{{-- The gear in the phone app: everything else is the website; and signing out. --}}
@extends('layouts.phone')

@section('title', __('app.gear.title'))

@section('head')
    <a class="phone-head-back" href="{{ route('app.documents') }}" aria-label="{{ __('app.docs.back') }}"><x-icon name="chevron-left" /></a>
    <h1>{{ __('app.gear.title') }}</h1>
@endsection

@section('content')
    <p class="muted">{{ auth()->user()?->name }}</p>
    <ul class="phone-list">
        <li class="phone-row"><a class="phone-row-main" href="{{ route('app.scanner') }}"><x-icon name="printer" /><span>{{ __('app.scanner.title') }}</span></a></li>
        <li class="phone-row"><a class="phone-row-main" href="{{ route('home') }}"><x-icon name="home" /><span>{{ __('app.gear.website') }}</span></a></li>
        <li class="phone-row"><a class="phone-row-main" href="{{ route('settings.index') }}"><x-icon name="settings" /><span>{{ __('app.gear.settings') }}</span></a></li>
    </ul>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="button-danger phone-wide"><x-icon name="power" />{{ __('app.gear.logout') }}</button>
    </form>
@endsection
