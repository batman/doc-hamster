@extends('layouts.app')

@section('title', __('settings.title'))

@section('content')
    <h1>{{ __('settings.title') }}</h1>

    <div class="settings-groups">
        @foreach ($groups as $group => $links)
            <div class="settings-group">
                <h2>{{ __('settings.groups.'.$group) }}</h2>
                <ul>
                    @foreach ($links as [$key, $route, $icon])
                        <li>
                            <a class="settings-link" href="{{ route($route) }}">
                                <x-icon :name="$icon" />
                                <span><strong>{{ __('settings.links.'.$key.'.title') }}</strong><span class="muted">{{ __('settings.links.'.$key.'.text') }}</span></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endsection
