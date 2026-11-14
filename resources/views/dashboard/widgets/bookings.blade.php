{{-- Offene Buchungen (A349): bookings without a document per account with their sum; a row opens the account filtered to "offen". --}}
@if ($rows === [])
    <p class="list-empty">{{ __('dashboard.bookings_empty') }}</p>
@else
    <ul class="pick-list dash-list">
        @foreach ($rows as $row)
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon name="building-bank" /></span>
                <span><strong><a href="{{ route('bank.account', [$row['account']->id, 'status' => \App\Domain\Banking\Transaction::OPEN]) }}" data-window>{{ $row['account']->name }}</a></strong><small>{{ trans_choice('dashboard.bookings_count', $row['count'], ['count' => $row['count']]) }}</small></span>
                <span class="row-amount @if (! str_starts_with($row['sum'], "\u{2212}")) is-income @endif">{{ $row['sum'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
