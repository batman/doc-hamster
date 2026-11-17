{{-- Vorschläge (D-040): the assistant's proposals as Empfehlungskacheln, grouped by kind. Each tile: symbol and kind,
     the assistant's reason, what it names (links open the document or booking as a window), Übernehmen / Ablehnen; a
     duplicate also "Das andere behalten". "Alle übernehmen" per kind. Nothing changes before a click. --}}
@extends('layouts.app')

@section('title', __('proposals.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('proposals.title')" icon="checks" kind="proposals" :url="route('proposals.index')" :close="route('home')">
        @if (session('status'))
            <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
        @endif
        @if (session('status_error'))
            <div class="toast toast-error" role="alert" data-toast><x-icon name="alert-triangle" /><span>{{ session('status_error') }}</span></div>
        @endif
        <p class="muted">{{ __('proposals.intro') }}</p>
        @forelse ($groups as $kind => $group)
            <section class="proposal-group">
                <h2 class="card-head"><x-icon :name="$group['icon']" />{{ __('proposals.kinds.'.$kind) }} <span class="pill">{{ count($group['tiles']) }}</span>
                    @if (count($group['tiles']) > 1)
                        <form method="post" action="{{ route('proposals.accept-all') }}" class="card-action">
                            @csrf
                            <input type="hidden" name="kind" value="{{ $kind }}">
                            <button type="submit" class="button-small button-quiet"><x-icon name="checks" />{{ __('proposals.accept_all') }}</button>
                        </form>
                    @endif
                </h2>
                <div class="proposal-tiles">
                    @foreach ($group['tiles'] as ['proposal' => $proposal, 'lines' => $lines])
                        <article class="card">
                            <h3 class="card-head"><x-icon :name="$group['icon']" />{{ __('proposals.tile.'.$kind) }}</h3>
                            @if ($proposal->reason)
                                <p class="card-text">{{ $proposal->reason }}</p>
                            @endif
                            <dl class="proposal-lines">
                                @foreach ($lines as $line)
                                    <dt>{{ $line['label'] }}</dt>
                                    <dd>@if ($line['url'])<a href="{{ $line['url'] }}" data-window>{{ $line['text'] }}</a>@else{{ $line['text'] }}@endif</dd>
                                @endforeach
                            </dl>
                            <p class="card-note">{{ __('proposals.from', ['name' => $proposal->assistant ?? __('proposals.assistant'), 'date' => $proposal->created_at->format('d.m.Y H:i')]) }}</p>
                            <div class="actions">
                                <form method="post" action="{{ route('proposals.accept', $proposal->id) }}">
                                    @csrf
                                    <button type="submit"><x-icon name="check" />{{ __('proposals.accept') }}</button>
                                </form>
                                @if ($kind === \App\Domain\Assistant\Proposal::DUPLICATE)
                                    <form method="post" action="{{ route('proposals.accept', $proposal->id) }}">
                                        @csrf
                                        <input type="hidden" name="swap" value="1">
                                        <button type="submit" class="button-quiet"><x-icon name="arrows-left-right" />{{ __('proposals.swap') }}</button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('proposals.reject', $proposal->id) }}">
                                    @csrf
                                    <button type="submit" class="button-quiet"><x-icon name="x" />{{ __('proposals.reject') }}</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <x-empty icon="checks" :text="__('proposals.none')" />
        @endforelse
    </x-window>
    </div>
@endsection
