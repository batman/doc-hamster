{{-- Einstellungen as columns: groups, entries, page. At most two are open; app.js folds the others to strips. --}}
@extends('layouts.app')

@section('main_class', 'page page-split')

@section('content')
    @php
        $groups = \App\Http\SettingsMenu::groups();
        [$group, $item] = \App\Http\SettingsMenu::current(request());
        $current = $group !== null ? ($groups[$group] ?? null) : null;
        $hasPage = trim($__env->yieldContent('page')) !== '';
    @endphp
    <div class="cols" data-cols>
        <button type="button" class="col-strip" data-strip="0" aria-label="{{ __('settings.expand', ['name' => __('settings.title')]) }}" @unless ($hasPage) hidden @endunless>
            <x-icon name="chevron-right" />
            <span class="strip-label"><x-icon name="settings" /><span>{{ __('settings.title') }}</span>@if ($current)<span>–</span><x-icon :name="$current['icon']" /><span>{{ __('settings.groups.'.$group) }}</span>@endif</span>
        </button>
        <nav class="col col-nav @if (! $current) is-last @endif" data-col="0" aria-label="{{ __('settings.title') }}" @if ($hasPage) hidden @endif>
            <div class="col-head">
                <h2>{{ __('settings.title') }}</h2>
                @if ($current)
                    <button type="button" class="icon-button" data-fold="0" aria-label="{{ __('settings.fold') }}" data-tooltip="{{ __('settings.fold') }}"><x-icon name="chevron-left" /></button>
                @endif
            </div>
            <ul class="col-list">
                @foreach ($groups as $key => $entry)
                    <li><a class="col-item" href="{{ route('settings.group', $key) }}" @if ($key === $group) aria-current="true" @endif><x-icon :name="$entry['icon']" /><span>{{ __('settings.groups.'.$key) }}</span><span class="go"><x-icon name="chevron-right" /></span></a></li>
                @endforeach
            </ul>
        </nav>

        @if ($current)
            <button type="button" class="col-strip" data-strip="1" aria-label="{{ __('settings.expand', ['name' => __('settings.groups.'.$group)]) }}" hidden>
                <x-icon name="chevron-right" />
                <span class="strip-label"><x-icon :name="$current['icon']" /><span>{{ __('settings.groups.'.$group) }}</span></span>
            </button>
            <div class="col {{ $__env->hasSection('pane') ? 'col-pane' : 'col-nav' }} @if (! $hasPage) is-last @endif" data-col="1">
                @hasSection('pane')
                    @yield('pane')
                @else
                    <div class="col-head">
                        <h2>{{ __('settings.groups.'.$group) }}</h2>
                        @if ($hasPage)
                            <button type="button" class="icon-button" data-fold="1" aria-label="{{ __('settings.fold') }}" data-tooltip="{{ __('settings.fold') }}"><x-icon name="chevron-left" /></button>
                        @endif
                    </div>
                    <ul class="col-list">
                        @foreach ($current['items'] as $entry)
                            <li><a class="col-item" href="{{ route($entry['route']) }}" @if ($entry['key'] === $item) aria-current="true" @endif><x-icon :name="$entry['icon']" /><span>{{ __('settings.links.'.$entry['key'].'.title') }}</span><span class="go"><x-icon name="chevron-right" /></span></a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if ($hasPage)
            <section class="col col-page">
                @yield('page')
            </section>
        @endif
    </div>
@endsection
