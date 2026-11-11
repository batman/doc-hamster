{{-- Einstellungen as columns: groups, entries, page. Each pane folds to a strip on its own (app.js); the page gives way.
     On phones only the last level shows, with a back link. --}}
@extends('layouts.app')

@section('main_class', 'page page-split')

@section('content')
    @php
        $groups = \App\Http\SettingsMenu::groups();
        [$group, $item] = \App\Http\SettingsMenu::current(request());
        $current = $group !== null ? ($groups[$group] ?? null) : null;
        $hasPage = trim($__env->yieldContent('page')) !== '';
        // Point 37: a group with a single entry and no own middle content gets no middle column.
        $single = $current !== null && count($current['items']) === 1 && ! $__env->hasSection('pane');
        $toGroup = $hasPage && $current && ! $current['leaf'] && ! $single;
    @endphp
    <div class="cols" data-cols data-wall-label="{{ __('settings.wall') }}">
        @if ($current)
            <a class="col-back" href="{{ $toGroup ? route('settings.group', $group) : route('settings.index') }}"><x-icon name="chevron-left" /><span>{{ $toGroup ? __('settings.groups.'.$group) : __('settings.title') }}</span></a>
        @endif
        <button type="button" class="col-strip tooltip-right" data-strip="0" data-tooltip="{{ __('settings.open') }}" aria-label="{{ __('settings.expand', ['name' => __('settings.title')]) }}" @unless ($hasPage) hidden @endunless>
            <x-icon name="chevron-right" />
            <span class="strip-label"><x-icon name="settings" /><span>{{ __('settings.title') }}</span></span>
        </button>
        <nav class="col col-nav @if (! $current) is-last @endif" data-col="0" aria-label="{{ __('settings.title') }}" @if ($hasPage) hidden @endif>
            <div class="col-head">
                <h2>{{ __('settings.title') }}</h2>
                @if ($current)
                    <button type="button" class="col-fold" data-fold="0" aria-label="{{ __('settings.fold') }}" data-tooltip="{{ __('settings.fold') }}"><x-icon name="chevron-left" /></button>
                @endif
            </div>
            <ul class="col-list">
                @foreach ($groups as $key => $entry)
                    <li><a class="col-item tooltip-right" data-tooltip="{{ __('settings.groups.'.$key) }}" href="{{ route('settings.group', $key) }}" @if ($key === $group) aria-current="true" @endif><x-icon :name="$entry['icon']" /><span>{{ __('settings.groups.'.$key) }}</span><span class="go"><x-icon name="chevron-right" /></span></a></li>
                @endforeach
            </ul>
            @yield('nav_footer')
        </nav>

        @if ($current && ! $single)
            <button type="button" class="col-strip tooltip-right" data-strip="1" data-tooltip="{{ __('settings.open') }}" aria-label="{{ __('settings.expand', ['name' => __('settings.groups.'.$group)]) }}" hidden>
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
                            <button type="button" class="col-fold" data-fold="1" aria-label="{{ __('settings.fold') }}" data-tooltip="{{ __('settings.fold') }}"><x-icon name="chevron-left" /></button>
                        @endif
                    </div>
                    <ul class="col-list">
                        @foreach ($current['items'] as $entry)
                            <li><a class="col-item tooltip-right" data-tooltip="{{ __('settings.links.'.$entry['key'].'.title') }}" href="{{ route($entry['route']) }}" @if ($entry['key'] === $item) aria-current="true" @endif><x-icon :name="$entry['icon']" /><span>{{ __('settings.links.'.$entry['key'].'.title') }}</span><span class="go"><x-icon name="chevron-right" /></span></a></li>
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
