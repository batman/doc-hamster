{{-- Einstellungen → Module → Revolut Business: the four steps (signing key, API key, accounts, Probelauf), then the
     automatic fetching and the switch for Zahlungs-Entwürfe. Only building blocks of the styleguide. --}}
@extends('layouts.settings')

@section('title', 'Revolut Business')

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    @if (session('error'))
        <p class="error" role="alert">{{ session('error') }}</p>
    @endif
    @php
        $connected = $settings->apiKey() !== null && $accounts !== [];
        $confirmed = $settings->confirmed();
    @endphp

    <section class="card">
        <h2 class="card-head"><x-icon name="building-bank" />{{ __('m-revolut::texts.status') }}
            <span @class(['pill', 'pill-ok' => $confirmed && $settings->error() === null, 'pill-warning' => $settings->error() !== null])>{{ __('m-revolut::texts.state_'.($settings->error() !== null ? 'error' : ($confirmed ? 'on' : ($connected ? 'connected' : 'off')))) }}</span>
        </h2>
        <p class="card-text">{{ __('m-revolut::texts.intro') }}</p>
        @if ($settings->error() !== null)
            <p class="error" role="alert">{{ $settings->error() }}</p>
        @endif
        @if ($confirmed)
            <p class="card-text">{{ __('m-revolut::texts.last_sync', ['time' => $settings->time('synced_at')?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '–']) }}</p>
            <form method="post" action="{{ route('m.revolut.sync') }}">
                @csrf
                <button type="submit" class="button-small"><x-icon name="refresh" />{{ __('m-revolut::texts.sync_now') }}</button>
            </form>
        @endif
        <form method="post" action="{{ route('m.revolut.environment') }}" class="actions">
            @csrf
            <span class="switcher" role="group" aria-label="{{ __('m-revolut::texts.environment') }}">
                <button type="submit" name="environment" value="live" aria-pressed="{{ $settings->environment() === 'live' ? 'true' : 'false' }}">{{ __('m-revolut::texts.live') }}</button>
                <button type="submit" name="environment" value="sandbox" aria-pressed="{{ $settings->environment() === 'sandbox' ? 'true' : 'false' }}">{{ __('m-revolut::texts.sandbox') }}</button>
            </span>
        </form>
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="lock" />{{ __('m-revolut::texts.step1') }}</h2>
        @if (! $sodium)
            <p class="error" role="alert">{{ __('m-revolut::texts.no_sodium') }}</p>
        @elseif ($settings->publicPem() === null)
            <p class="card-text">{{ __('m-revolut::texts.step1_text') }}</p>
            <form method="post" action="{{ route('m.revolut.keygen') }}">
                @csrf
                <button type="submit" class="button-small"><x-icon name="lock" />{{ __('m-revolut::texts.keygen') }}</button>
            </form>
        @else
            <p class="card-text">{{ __('m-revolut::texts.step1_done') }}</p>
            <x-copy-text id="revolut-public-key" :label="__('m-revolut::texts.public_key')" :text="$settings->publicPem()" rows="4" />
            <ol class="card-text">
                @foreach (__('m-revolut::texts.console_steps') as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        @endif
    </section>

    @if ($settings->publicPem() !== null)
        <section class="card">
            <h2 class="card-head"><x-icon name="plug" />{{ __('m-revolut::texts.step2') }}</h2>
            <p class="card-text">{{ $settings->keyHint() !== null ? __('m-revolut::texts.key_saved', ['hint' => $settings->keyHint()]) : __('m-revolut::texts.step2_text') }}</p>
            <form method="post" action="{{ route('m.revolut.key') }}">
                @csrf
                <div class="field-row"><input type="password" name="api_key" autocomplete="off" aria-label="{{ __('m-revolut::texts.api_key') }}" placeholder="{{ __('m-revolut::texts.api_key') }}" required><button type="submit"><x-icon name="plug" />{{ __('m-revolut::texts.connect') }}</button></div>
            </form>
        </section>
    @endif

    @if ($connected)
        <section class="card">
            <h2 class="card-head"><x-icon name="arrows-left-right" />{{ __('m-revolut::texts.step3') }}</h2>
            <p class="card-text">{{ __('m-revolut::texts.step3_text') }}</p>
            <form method="post" action="{{ route('m.revolut.map') }}" data-autosave>
                @csrf
                <ul class="pick-list">
                    @foreach ($accounts as $account)
                        @php $chosen = $settings->mapping()[$account['id']] ?? 'new'; @endphp
                        <li class="pick-row is-static">
                            <span class="row-icon"><x-icon name="building-bank" /></span>
                            <span>
                                <strong>{{ \DocHamsterModules\Revolut\Sync::name($account) }}</strong>
                                <small>{{ $account['iban'] ?? __('m-revolut::texts.no_iban') }} @if ($account['state'] !== 'active') · {{ $account['state'] }} @endif</small>
                            </span>
                            <select name="mapping[{{ $account['id'] }}]" aria-label="{{ __('m-revolut::texts.target') }}">
                                <option value="new" @selected($chosen === 'new')>{{ __('m-revolut::texts.target_new') }}</option>
                                @foreach ($targets->where('currency', $account['currency']) as $target)
                                    <option value="{{ $target->id }}" @selected($chosen === $target->id || $target->source_key === 'revolut:'.$account['id'])>{{ $target->name }}</option>
                                @endforeach
                            </select>
                        </li>
                    @endforeach
                </ul>
                <noscript><button type="submit" class="button-small">{{ __('m-revolut::texts.save') }}</button></noscript>
            </form>
        </section>

        <section class="card">
            <h2 class="card-head"><x-icon name="eye" />{{ __('m-revolut::texts.step4') }}</h2>
            <p class="card-text">{{ __($confirmed ? 'm-revolut::texts.step4_again' : 'm-revolut::texts.step4_text') }}</p>
            <form method="post" action="{{ route('m.revolut.probe') }}">
                @csrf
                <button type="submit" class="button-small button-quiet"><x-icon name="eye" />{{ __('m-revolut::texts.probe') }}</button>
            </form>
            @if (is_array($probe))
                @foreach ($probe as $part)
                    <h3 class="card-head">{{ \DocHamsterModules\Revolut\Sync::name($part['account']) }} → {{ $part['target'] }}
                        <span class="pill">{{ __('m-revolut::texts.probe_new', ['count' => $part['new']]) }}</span>
                        <span class="pill pill-ok">{{ __('m-revolut::texts.probe_known', ['count' => $part['known']]) }}</span>
                    </h3>
                    <ul class="pick-list">
                        @forelse ($part['rows'] as $row)
                            @php $line = $row['line']; @endphp
                            <li class="pick-row is-static">
                                <span>
                                    <strong>{{ $line['counterparty'] ?? $line['purpose'] ?? '–' }}</strong>
                                    <small>{{ \Illuminate\Support\Carbon::parse($line['booking_date'])->format('d.m.Y') }} {{ $line['booked_time'] }} · {{ $line['kind'] }} · {{ $line['bank_state'] }}@if ($line['purpose'] && $line['purpose'] !== $line['counterparty']) · {{ $line['purpose'] }}@endif</small>
                                </span>
                                <span class="pill">{{ __('m-revolut::texts.action_'.$row['action']) }}</span>
                                <span class="row-amount @if ($line['amount_minor'] > 0) is-income @endif">{{ \App\Domain\Banking\Transaction::signed($line['amount_minor'], $line['currency'] ?? $part['account']['currency']) }}</span>
                            </li>
                        @empty
                            <x-empty tag="li" icon="building-bank" :text="__('m-revolut::texts.probe_none')" />
                        @endforelse
                    </ul>
                @endforeach
                <form method="post" action="{{ route('m.revolut.accept') }}" class="actions">
                    @csrf
                    <button type="submit"><x-icon name="check" />{{ __('m-revolut::texts.accept') }}</button>
                </form>
            @endif
        </section>

        <section class="card">
            <h2 class="card-head"><x-icon name="send" />{{ __('m-revolut::texts.drafts') }}</h2>
            <p class="card-text">{{ __('m-revolut::texts.drafts_text') }}</p>
            <form method="post" action="{{ route('m.revolut.drafts') }}" class="actions">
                @csrf
                <span class="switcher" role="group" aria-label="{{ __('m-revolut::texts.drafts') }}">
                    <button type="submit" name="on" value="0" aria-pressed="{{ $settings->drafts() ? 'false' : 'true' }}">{{ __('m-revolut::texts.off') }}</button>
                    <button type="submit" name="on" value="1" aria-pressed="{{ $settings->drafts() ? 'true' : 'false' }}">{{ __('m-revolut::texts.on') }}</button>
                </span>
            </form>
        </section>
    @endif
@endsection
