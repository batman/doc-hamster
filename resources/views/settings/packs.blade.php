{{-- Einstellungen → Export → Belegpaket (point 211, D-018): period (quick choice or dates) and the ticked accounts →
     "Vorschau" (counts, missing) → "Belegpaket erstellen" → download. Below: every frozen pack with its version. --}}
@extends('layouts.settings')

@section('title', __('receipt_pack.title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="file-type-pdf" />{{ __('receipt_pack.create_title') }}</h2>
        <p class="card-text">{{ __('receipt_pack.intro') }}</p>
        <form method="get" action="{{ route('packs.index') }}">
            <input type="hidden" name="preview" value="1">
            <fieldset>
                <legend>{{ __('receipt_pack.period') }}</legend>
                <span class="switcher" role="group" aria-label="{{ __('receipt_pack.period') }}">
                    @foreach (['month', 'quarter', 'year'] as $each)
                        <a href="{{ route('packs.index', ['preset' => $each]) }}" aria-pressed="{{ $preset === $each ? 'true' : 'false' }}">{{ __('receipt_pack.presets.'.$each) }}</a>
                    @endforeach
                </span>
                <div class="search-row">
                    <div class="field">
                        <label for="pack-from">{{ __('receipt_pack.from') }}</label>
                        <input id="pack-from" type="date" name="from" value="{{ $period->from->toDateString() }}" required>
                    </div>
                    <div class="field">
                        <label for="pack-to">{{ __('receipt_pack.to') }}</label>
                        <input id="pack-to" type="date" name="to" value="{{ $period->to->toDateString() }}" required>
                    </div>
                </div>
            </fieldset>
            <fieldset>
                <legend>{{ __('receipt_pack.accounts') }}</legend>
                @forelse ($accounts as $account)
                    <label class="check"><input type="checkbox" name="accounts[]" value="{{ $account->id }}" @checked(in_array($account->id, $chosen, true))>
                        <span><x-icon :name="$account->icon()" /> <strong>{{ $account->name }}</strong>
                            <small class="muted">{{ $account->isCash() ? __('receipt_pack.cash') : \App\Domain\ReceiptPack\IbanMask::mask($account->iban) }}</small></span></label>
                @empty
                    <x-empty icon="building-bank" :text="__('receipt_pack.no_accounts')" />
                @endforelse
            </fieldset>
            <button type="submit" @disabled($accounts->isEmpty())><x-icon name="eye" />{{ __('receipt_pack.preview') }}</button>
        </form>
    </section>

    @if ($preview !== null)
        @php $figures = $preview->figures(); @endphp
        <section class="card">
            <h2 class="card-head"><x-icon name="list-details" />{{ __('receipt_pack.preview_title', ['period' => $period->human()]) }}</h2>
            <ul class="pick-list">
                @foreach ($preview->sections as $section)
                    @php $f = $section->figures(); @endphp
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon :name="$section->kind === 'cash' ? 'cash' : 'building-bank'" /></span>
                        <span><strong>{{ $section->name }}</strong>
                            <small>{{ trans_choice('receipt_pack.bookings', $f->count, ['count' => $f->count]) }} · {{ __('receipt_pack.with_receipt', ['count' => $f->withReceipt]) }}</small></span>
                        <span class="pill {{ $f->missingCount() > 0 ? 'pill-error' : 'pill-ok' }}">{{ __('receipt_pack.missing', ['count' => $f->missingCount()]) }}</span>
                    </li>
                @endforeach
                @if ($preview->loose !== [])
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon name="receipt" /></span>
                        <span><strong>{{ __('receipt_pack.loose') }}</strong><small>{{ trans_choice('receipt_pack.documents', count($preview->loose), ['count' => count($preview->loose)]) }}</small></span>
                    </li>
                @endif
            </ul>
            <p class="card-text">{{ __('receipt_pack.summary', ['bookings' => $figures->count, 'with' => $figures->withReceipt, 'none' => $figures->noReceipt]) }}
                @if ($figures->missingCount() > 0) <strong class="error">{{ __('receipt_pack.missing', ['count' => $figures->missingCount()]) }}</strong> @endif</p>
            @if ($background)
                <p class="card-note"><x-icon name="clock" /> {{ __('receipt_pack.background') }}</p>
            @endif
            <form method="post" action="{{ route('packs.store') }}" data-confirm-sudo>
                @csrf
                <input type="hidden" name="from" value="{{ $period->from->toDateString() }}">
                <input type="hidden" name="to" value="{{ $period->to->toDateString() }}">
                @foreach ($chosen as $id)
                    <input type="hidden" name="accounts[]" value="{{ $id }}">
                @endforeach
                <button type="submit"><x-icon name="file-type-pdf" />{{ __('receipt_pack.create') }}</button>
            </form>
        </section>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="archive" />{{ __('receipt_pack.list') }}</h2>
        <p class="card-note">{{ __('receipt_pack.list_note') }}</p>
        <ul class="pick-list">
            @forelse ($packs as $pack)
                <li class="pick-row is-static" @if ($pack->isRunning()) data-pack-running @endif>
                    <span class="row-icon"><x-icon name="file-type-pdf" /></span>
                    <span><strong>{{ $pack->fileName() }}</strong>
                        <small>{{ $pack->period()->human() }} · {{ collect($pack->account_ids)->map(fn ($id) => $accountNames[$id] ?? '?')->implode(', ') }} · {{ __('receipt_pack.made', ['date' => $pack->created_at->format('d.m.Y H:i')]) }}@if ($pack->pages) · {{ trans_choice('receipt_pack.pages', $pack->pages, ['count' => $pack->pages]) }}@endif</small></span>
                    @if ($pack->status !== \App\Domain\ReceiptPack\ReceiptPack::DONE)
                        <span class="pill {{ $pack->status === 'failed' ? 'pill-error' : '' }}">{{ __('receipt_pack.status.'.$pack->status, ['progress' => $pack->progress]) }}</span>
                    @elseif (($pack->summary['missing'] ?? 0) > 0)
                        <span class="pill pill-error">{{ __('receipt_pack.missing', ['count' => $pack->summary['missing']]) }}</span>
                    @endif
                    @if ($pack->isReady())
                        <a class="icon-button" href="{{ route('packs.download', $pack->id) }}" aria-label="{{ __('receipt_pack.download') }}: {{ $pack->fileName() }}" data-tooltip="{{ __('receipt_pack.download') }}"><x-icon name="download" />@if ($pack->downloaded_at === null && $pack->created_by === auth()->id())<span class="badge badge-alert">1</span>@endif</a>
                    @endif
                </li>
            @empty
                <x-empty tag="li" icon="file-type-pdf" :text="__('receipt_pack.none')" />
            @endforelse
        </ul>
    </section>
@endsection
