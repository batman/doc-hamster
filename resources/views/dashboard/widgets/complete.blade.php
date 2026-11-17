{{-- Vollständigkeit (A55): the last twelve months for the tax advisor – red: bookings without a document, yellow:
     linked documents without amount, date or sender, green: complete. A month with bookings opens the bank. --}}
<ul class="pick-list dash-list">
    @foreach ($rows as $row)
        <li class="pick-row is-static">
            <span class="row-icon"><x-icon name="calendar" /></span>
            <span><strong>@if ($row['open'] > 0)<a href="{{ route('bank.index') }}" data-window>{{ $row['month']->translatedFormat('F Y') }}</a>@else{{ $row['month']->translatedFormat('F Y') }}@endif</strong><small>@if ($row['state'] === 'ok'){{ __('dashboard.complete_ok') }}@else{{ collect([$row['open'] > 0 ? trans_choice('dashboard.complete_error', $row['open'], ['count' => $row['open']]) : null, $row['incomplete'] > 0 ? trans_choice('dashboard.complete_warning', $row['incomplete'], ['count' => $row['incomplete']]) : null])->filter()->implode(' · ') }}@endif</small></span>
            <span class="pill pill-{{ $row['state'] === 'error' ? 'error' : ($row['state'] === 'warning' ? 'warning' : 'ok') }}">{{ __('dashboard.complete_pill.'.$row['state']) }}</span>
        </li>
    @endforeach
</ul>
