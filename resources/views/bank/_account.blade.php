{{-- One account as a window (A267): its bookings newest first. In the toolbar the status filter (A304) and
     Eingänge/Ausgänge (A311) as button groups with counts (bank._filters), one click filters (modules/bank.js reloads
     this window); when they do not fit they show as dropdowns (point 150); beside them Liste | Kalender (159.2, bank._calendar).
     The search panel (A305) holds the period with the quick picks of Dokumente; a set period shows as a chip (159.1). On top
     the sums of what is shown (A310) and the imported periods (A296). Rows: an icon for the kind of booking (A312), the bank's type and a
     pending/reverted state as small pills (A293), amounts always with sign (A309). A statement dropped onto the window
     imports into this account; a document dragged onto a booking row links it (A269, data-doc-drop). --}}
@php
    // F40: "Bank ansehen" only – no statement drop, no cash entries, no links.
    $mayEdit = Gate::allows('banking.import');
    $statusClass =['open' => 'pill-warning', 'suggested' => 'pill-new', 'linked' => 'pill-ok', 'none' => ''];
    $query = array_filter(['status' => $status, 'flow' => $flow, 'q' => $accountSearch !== '' ? $accountSearch : null, 'from' => $from, 'to' => $to,
        'view' => $calendar ? 'calendar' : null, 'month' => $calendar ? $calendar['month']->format('Y-m') : null]);
    $accountUrl = route('bank.account', [$account->id, ...$query]);
    $with = static fn (array $change): string => route('bank.account', [$account->id, ...array_filter([...$query, ...$change])]);
    $money = static fn (int $minor): string => \App\Domain\Banking\Transaction::signed($minor, $account->currency);
    $day = static fn (\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): string => $from->year === $to->year
        ? $from->format('d.m.').'–'.$to->format('d.m.Y') : $from->format('d.m.Y').'–'.$to->format('d.m.Y');
    $statusOptions = ['' => [__('bank.all'), null], ...collect(\App\Domain\Banking\Transaction::STATUSES)->mapWithKeys(static fn (string $s): array => [$s => [__('bank.status_short.'.$s), $statusCounts[$s]]])->all()];
    $flowOptions = ['' => __('bank.flow_all'), 'in' => __('bank.flow_in'), 'out' => __('bank.flow_out')];
    $viewOptions = ['list' => 'list', 'calendar' => 'calendar'];
    // 159.1: the period buttons of Dokumente (DocumentSearch presets) and which one the set dates are.
    $ranges = [];
    foreach (\App\Http\Documents\DocumentSearch::PRESETS as $presetKey) { $ranges[$presetKey] = implode(' ', \App\Http\Documents\DocumentSearch::presetRange($presetKey)); }
    $preset = $from || $to ? (array_search($from.' '.$to, $ranges, true) ?: 'custom') : null;
    $date = static fn (string $value): string => \Illuminate\Support\Carbon::parse($value)->format('d.m.Y');
    $periodChip = match (true) {
        $preset !== null && $preset !== 'custom' => __('documents.adv.quick.'.$preset),
        $from !== null && $from === $to => $date($from),
        $from !== null && $to !== null => $date($from).' – '.$date($to),
        $from !== null => __('bank.period_since', ['date' => $date($from)]),
        $to !== null => __('bank.period_until', ['date' => $date($to)]),
        default => null,
    };
@endphp
<x-window :title="$account->name" :icon="$account->icon()" kind="bank-account" min="24rem" optimal="38rem" max="56rem"
          :url="$accountUrl" :close="route('bank.index')"
          :search="__('bank.search')" search-params="from to" :search-count="($from ? 1 : 0) + ($to ? 1 : 0)"
          :data-statement-drop="$account->isCash() || ! $mayEdit ? null : route('bank.import.upload')" data-account="{{ $account->id }}" data-drop-label="{{ __('bank.drop_into', ['name' => $account->name]) }}">
    <x-slot:actions>
        @include('bank._filters')
    </x-slot:actions>
    <x-slot:searchOptions>
        {{-- 159.1: the period block of Dokumente (quick picks, "Eigener" shows the dates; modules/documents-search.js). --}}
        <div class="search-sections" data-doc-search>
            <div data-search-section="period">
                <div class="search-line">
                    <span>{{ __('bank.period') }}</span>
                    <span class="button-choice" data-choice data-choice-toggle data-period-choice>
                        <input type="hidden" value="{{ $preset ?? '' }}">
                        <span class="switcher" role="group" aria-label="{{ __('bank.period') }}">
                            @foreach ($ranges as $presetKey => $presetRange)
                                <button type="button" data-value="{{ $presetKey }}" data-range="{{ $presetRange }}" aria-pressed="{{ $preset === $presetKey ? 'true' : 'false' }}">{{ __('documents.adv.quick.'.$presetKey) }}</button>
                            @endforeach
                            <button type="button" data-value="custom" aria-pressed="{{ $preset === 'custom' ? 'true' : 'false' }}">{{ __('documents.adv.quick.custom') }}</button>
                        </span>
                    </span>
                </div>
                <div class="search-line" data-period-custom @if ($preset !== 'custom') hidden @endif>
                    <span>{{ __('bank.period_from') }} – {{ __('bank.period_to') }}</span>
                    <span class="search-row">
                        <input type="date" class="input-small" name="from" value="{{ $from }}" aria-label="{{ __('bank.period_from') }}">
                        <span class="muted">–</span>
                        <input type="date" class="input-small" name="to" value="{{ $to }}" aria-label="{{ __('bank.period_to') }}">
                    </span>
                </div>
            </div>
        </div>
    </x-slot:searchOptions>
    @if (session('status') && ! $transaction)
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    <div data-search-count="{{ ($from ? 1 : 0) + ($to ? 1 : 0) }}" hidden></div>
    @if ($periodChip !== null)
        {{-- 159.1: the set period as a chip; its X drops from and to at once. --}}
        <div class="filter-chips">
            <span class="filter-chip">{{ __('bank.period') }}: {{ $periodChip }}<a class="control-button" href="{{ $with(['from' => null, 'to' => null]) }}" data-bank-filter aria-label="{{ __('bank.period_remove') }}" data-tooltip="{{ __('bank.period_remove') }}"><x-icon name="x" /></a></span>
        </div>
    @endif
    {{-- Fresh filters with counts for the toolbar after a reload of only the body (search, point 149, A326). --}}
    <template data-bank-filters-fresh>@include('bank._filters')</template>
    @if ($account->isCash() && $mayEdit)
        {{-- Point 190: a cash entry, saved at once; the window reloads with it. --}}
        <form class="cash-entry" method="post" action="{{ route('bank.cash.store', $account->id) }}" data-bank-action>
            @csrf
            @include('bank._cash-fields', ['prefix' => 'cash-new-'.$account->id])
            <button type="submit"><x-icon name="plus" />{{ __('bank.cash_add') }}</button>
        </form>
    @endif
    {{-- A310: the sums of what is shown. --}}
    <p class="amount-summary">
        <span>{{ __('bank.totals_in') }} <span class="row-amount is-income">{{ $money($totals['in']) }}</span></span>
        <span>{{ __('bank.totals_out') }} <span class="row-amount">{{ $money($totals['out']) }}</span></span>
        <span>{{ __('bank.totals_sum') }} <span class="row-amount @if ($totals['sum'] > 0) is-income @endif">{{ $money($totals['sum']) }}</span></span>
        @if ($account->isCash())
            {{-- Point 190: the running balance like a bank's saldo (opening balance plus every entry). --}}
            <span>{{ __('bank.cash_balance') }} <span class="row-amount @if ($cashBalance > 0) is-income @endif">{{ $money($cashBalance) }}</span></span>
        @endif
    </p>
    @if ($periods !== [])
        <p class="card-note">{{ __('bank.imported_periods') }}: {{ collect($periods)->map(static fn (array $p): string => $day($p['from'], $p['to']))->implode(' · ') }}</p>
    @endif
    @if ($calendar)
        @include('bank._calendar')
    @else
    <ul class="pick-list">
        @forelse ($transactions as $entry)
            @php $state = $entry->status(); @endphp
            <li class="pick-row is-static @if ($transaction?->id === $entry->id) is-marked @endif @if ($entry->isUnsettled()) is-off @endif" @if ($mayEdit) data-doc-drop="{{ route('bank.link', $entry->id) }}" data-booking-drag="{{ route('bank.link', $entry->id) }}" @endif data-booking-label="{{ $entry->counterparty ?? $entry->purpose ?? '–' }} · {{ $entry->signedAmount() }}">
                <span class="row-icon" data-tooltip="{{ __('bank.nature.'.$entry->nature()) }}"><x-icon :name="$entry->natureIcon()" /></span>
                <span>
                    <strong><a href="{{ route('bank.show', $entry->id) }}" data-window>{{ $entry->counterparty ?? $entry->purpose ?? '–' }}</a></strong>
                    <small>{{ $entry->bookedOn() }}@if ($entry->purpose && $entry->counterparty) · {{ \Illuminate\Support\Str::limit($entry->purpose, 80) }}@endif</small>
                </span>
                @if ($entry->kind)
                    <span class="pill">{{ \Illuminate\Support\Str::limit(ucfirst(mb_strtolower(str_replace('_', ' ', $entry->kind))), 24) }}</span>
                @endif
                @if ($entry->note)
                    {{-- A319: the booking has a note; the tooltip shows it. --}}
                    <span class="row-note" data-tooltip="{{ \Illuminate\Support\Str::limit($entry->note, 300) }}" aria-label="{{ __('bank.note') }}: {{ \Illuminate\Support\Str::limit($entry->note, 300) }}"><x-icon name="note" /></span>
                @endif
                @if ($entry->isUnsettled())
                    <span class="pill pill-warning">{{ __('bank.bank_state.'.$entry->bank_state) }}</span>
                @endif
                @if ($state === 'open' && $entry->suggested_reason_id)
                    <span class="pill pill-new">{{ __('bank.status_suggest_none') }}</span>
                @else
                    <span class="pill {{ $statusClass[$state] }}">{{ __('bank.status.'.$state) }}</span>
                @endif
                <span class="row-amount @if ($entry->amount_minor > 0) is-income @endif">{{ $entry->signedAmount() }}</span>
                @if ($entry->isManual() && $mayEdit)
                    @include('bank._cash-entry')
                @endif
            </li>
        @empty
            @if ($status || $flow || $from || $to || $accountSearch !== '')
                <x-empty tag="li" icon="filter" :text="__('bank.empty_filtered')" />
            @else
                <x-empty tag="li" icon="receipt" :text="$account->isCash() ? __('bank.empty') : __('empty.bookings')" />
            @endif
        @endforelse
    </ul>
    @include('bank._pages', ['pages' => $transactions])
    @endif
    <div hidden data-bank-texts data-drop-one="{{ __('bank.drop_one') }}" data-importing="{{ __('bank.importing') }}"></div>
</x-window>
