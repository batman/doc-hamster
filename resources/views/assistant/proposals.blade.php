{{-- Vorschläge (D-040, points 1–7): opened from Einstellungen → Assistenten ("Vorschläge ansehen") as a window on the
     right. Toolbar: search, the kinds and Alle | Sicher | Unsicher as Umschalter (A57; too narrow → the framework's
     dropdown). Body: "N übernehmen" / "N sichere übernehmen" for everything the filter shows (A59), one list of
     Empfehlungskacheln (A58), ten per page with the pager. A tile names its documents and bookings as Objektkacheln
     (point 6), two of them side by side with "Nebeneinander" (A62, data-window-also), ✓ / bin as icon buttons (point 5).
     JS modules/proposals.js: every action posts in the background and reloads only this window (A61). --}}
@extends('layouts.app')

@section('title', __('proposals.title'))

@section('main_class', 'page-windows')

@php
    $reload = static fn (array $set): string => route('proposals.index', array_filter([...$filter, ...$set], static fn ($v): bool => $v !== '' && $v !== null));
    $hidden = array_filter($filter, static fn (string $v): bool => $v !== '');
@endphp

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('proposals.title')" icon="checks" kind="proposals" min="22rem" optimal="44rem" max="80rem" priority="2" fit="wide"
        :url="$reload([])" :close="route('assistant.show')" :search="__('proposals.search')" search-live>
        <x-slot:actions>
            @if (count($kinds) > 1)
                <div class="switcher" role="group" aria-label="{{ __('proposals.filter_kind') }}">
                    <a href="{{ $reload(['kind' => null, 'page' => null]) }}" data-window-reload="kind" aria-pressed="{{ $filter['kind'] === '' ? 'true' : 'false' }}">{{ __('proposals.all') }} <span class="pill">{{ $total }}</span></a>
                    @foreach ($kinds as $kind => $count)
                        <a href="{{ $reload(['kind' => $kind, 'page' => null]) }}" data-window-reload="kind" aria-pressed="{{ $filter['kind'] === $kind ? 'true' : 'false' }}"><x-icon :name="\App\Http\Controllers\Assistant\ProposalController::ICONS[$kind]" />{{ __('proposals.kinds.'.$kind) }} <span class="pill">{{ $count }}</span></a>
                    @endforeach
                </div>
            @endif
            <div class="switcher" role="group" aria-label="{{ __('proposals.filter_sure') }}">
                @foreach (['' => 'all', 'sure' => 'sure', 'unsure' => 'unsure'] as $value => $label)
                    <a href="{{ $reload(['sure' => $value, 'page' => null]) }}" data-window-reload="sure" aria-pressed="{{ $filter['sure'] === $value ? 'true' : 'false' }}">{{ __('proposals.'.$label) }}</a>
                @endforeach
            </div>
        </x-slot:actions>

        @if (session('status'))
            <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
        @endif
        @if (session('status_error'))
            <div class="toast toast-error" role="alert" data-toast><x-icon name="alert-triangle" /><span>{{ session('status_error') }}</span></div>
        @endif
        <p class="muted">{{ __('proposals.intro') }}</p>

        @if ($pages->total() > 0)
            <div class="actions">
                @if ($sure > 0 && $sure < $pages->total())
                    <form method="post" action="{{ route('proposals.accept-all') }}" data-proposal-action>
                        @csrf
                        @foreach ($hidden as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                        <input type="hidden" name="only_sure" value="1">
                        <button type="submit" class="button-quiet"><x-icon name="circle-check" />{{ trans_choice('proposals.accept_sure_n', $sure, ['count' => $sure]) }}</button>
                    </form>
                @endif
                <form method="post" action="{{ route('proposals.accept-all') }}" data-proposal-action>
                    @csrf
                    @foreach ($hidden as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                    <button type="submit" class="button-quiet"><x-icon name="checks" />{{ trans_choice('proposals.accept_n', $pages->total(), ['count' => $pages->total()]) }}</button>
                </form>
            </div>
        @endif

        <div class="proposal-tiles">
            @forelse ($pages as $proposal)
                @php
                    $kind = $proposal->kind;
                    $objects = $tiles->objects($proposal);
                    $pair = count($objects) === 2 && $objects[0]['url'] && $objects[1]['url'];
                    $withProposal = static fn (string $url): string => $url.(str_contains($url, '?') ? '&' : '?').'proposal='.$proposal->id;
                    $sign = match ($kind) { \App\Domain\Assistant\Proposal::DUPLICATE => 'copy', \App\Domain\Assistant\Proposal::UNLINK => 'unlink', \App\Domain\Assistant\Proposal::FOLDER_MOVE => 'arrow-up-right', default => 'link' };
                    // A88: a new folder lists the documents it brings along (no sign between them).
                    $listed = $kind === \App\Domain\Assistant\Proposal::FOLDER_CREATE;
                @endphp
                <article class="card" data-window-row>
                    <h3 class="card-head"><x-icon :name="\App\Http\Controllers\Assistant\ProposalController::ICONS[$kind]" />{{ __('proposals.tile.'.$kind) }}
                        @if ($proposal->sure === true)<span class="pill pill-ok">{{ __('proposals.sure') }}</span>@elseif ($proposal->sure === false)<span class="pill pill-warning">{{ __('proposals.unsure') }}</span>@endif
                    </h3>
                    @if ($proposal->reason)
                        <p class="card-text">{{ $proposal->reason }}</p>
                    @endif
                    @if ($kind === \App\Domain\Assistant\Proposal::STRAIGHTEN)
                        <div class="proposal-preview">
                            <figure><img src="{{ route('proposals.preview', [$proposal->id, 'before']) }}" alt="{{ __('proposals.before') }}" loading="lazy"><figcaption>{{ __('proposals.before') }}</figcaption></figure>
                            <figure><img src="{{ route('proposals.preview', [$proposal->id, 'after']) }}" alt="{{ __('proposals.after') }}" loading="lazy"><figcaption>{{ __('proposals.after') }}</figcaption></figure>
                        </div>
                    @endif
                    @if ($objects !== [])
                        <div @class(['object-pair', 'is-list' => $listed])>
                            @foreach ($objects as $i => $object)
                                @if ($i > 0 && ! $listed)<span class="object-pair-sign"><x-icon :name="$sign" /></span>@endif
                                @include('assistant._object-tile', ['object' => $object, 'proposal' => $proposal])
                            @endforeach
                        </div>
                    @endif
                    @if (($values = $tiles->values($proposal)) !== [])
                        <dl class="proposal-lines">
                            @foreach ($values as $line)
                                <dt>{{ $line['label'] }}</dt>
                                <dd>{{ $line['text'] }}</dd>
                            @endforeach
                        </dl>
                    @endif
                    <p class="card-note">{{ __('proposals.from', ['name' => $proposal->assistant ?? __('proposals.assistant'), 'date' => $proposal->created_at->format('d.m.Y H:i')]) }}</p>
                    <div class="actions">
                        @include('assistant._proposal-buttons', ['proposal' => $proposal])
                        @if ($pair)
                            <a class="icon-button" href="{{ $withProposal($objects[0]['url']) }}" data-window data-window-also="{{ $withProposal($objects[1]['url']) }}"
                                aria-label="{{ __('proposals.side_by_side') }}" data-tooltip="{{ __('proposals.side_by_side') }}"><x-icon name="layout-columns" /></a>
                        @endif
                    </div>
                </article>
            @empty
                <x-empty icon="checks" :text="$total > 0 ? __('proposals.none_found') : __('proposals.none')" />
            @endforelse
        </div>

        <x-pager :pages="$pages" />
    </x-window>
    </div>
@endsection
