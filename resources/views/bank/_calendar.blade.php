{{-- The account window as a calendar (159.2, A332–A334; the calendar block of Dokumente): one month, the week starts on
     Monday, paging only between the first and the last month with bookings. A day shows its first bookings (amount with
     sign, A309), "+N weitere", its sum and an orange dot while a booking still lacks a document (A334). A click on the day
     shows the list of just that day (A333, from = to = the day; modules/bank.js reloads this window). --}}
@php
    $month = $calendar['month'];
    $range = $calendar['range'];
    $days = $calendar['days'];
    $dayUrl = static fn (string $iso): string => $with(['view' => null, 'month' => null, 'from' => $iso, 'to' => $iso]);
@endphp
<div class="calendar-head">
    <h2>{{ $month->locale(app()->getLocale())->translatedFormat('F Y') }}</h2>
    @foreach ([[-1, 'chevron-left', 'previous_month'], [1, 'chevron-right', 'next_month']] as [$step, $icon, $label])
        @php $target = $month->addMonths($step); @endphp
        @if ($range !== null && $target->betweenIncluded($range['first'], $range['last']))
            <a class="icon-button" href="{{ $with(['month' => $target->format('Y-m')]) }}" data-bank-filter aria-label="{{ __('documents.'.$label) }}" data-tooltip="{{ __('documents.'.$label) }}"><x-icon :name="$icon" /></a>
        @else
            <button type="button" class="icon-button" disabled aria-label="{{ __('documents.'.$label) }}"><x-icon :name="$icon" /></button>
        @endif
    @endforeach
</div>
@if ($range === null)
    <p class="list-empty">{{ $status || $flow || $from || $to || $accountSearch !== '' ? __('bank.empty_filtered') : __('bank.empty') }}</p>
@endif
<div class="calendar bank-calendar">
    @foreach (__('documents.weekdays') as $weekday)
        <div class="weekday">{{ $weekday }}</div>
    @endforeach
    @for ($i = 1; $i < $month->dayOfWeekIso; $i++)
        <div class="day is-other"></div>
    @endfor
    @for ($d = 1; $d <= $month->daysInMonth; $d++)
        @php $info = $days[$d] ?? null; @endphp
        @if ($info === null)
            <div class="day"><b>{{ $d }}</b></div>
        @else
            @php $iso = $month->setDay($d)->toDateString(); $label = __('bank.day_show', ['date' => $month->setDay($d)->format('d.m.Y')]); @endphp
            <div class="day has-entries" data-bank-day="{{ $dayUrl($iso) }}">
                <a class="day-head" href="{{ $dayUrl($iso) }}" data-bank-filter aria-label="{{ $label }}" data-tooltip="{{ $label }}">
                    <b>{{ $d }}</b>
                    @if ($info['open'])
                        <span class="day-open" role="img" aria-label="{{ __('bank.day_open') }}"></span>
                    @endif
                    <span class="row-amount @if ($info['sum'] > 0) is-income @endif">{{ $money($info['sum']) }}</span>
                </a>
                @foreach ($info['entries'] as $entry)
                    @php $name = $entry->counterparty ?? $entry->purpose ?? '–'; @endphp
                    <a class="entry" href="{{ route('bank.show', $entry->id) }}" data-window data-tooltip="{{ $name }}">
                        <span class="entry-name">{{ $name }}</span>
                        <span class="row-amount @if ($entry->amount_minor > 0) is-income @endif">{{ $entry->signedAmount() }}</span>
                    </a>
                @endforeach
                @if ($info['more'] > 0)
                    <a class="more" href="{{ $dayUrl($iso) }}" data-bank-filter>{{ __('bank.calendar_more', ['count' => $info['more']]) }}</a>
                @endif
            </div>
        @endif
    @endfor
    @for ($i = ($month->dayOfWeekIso - 1 + $month->daysInMonth) % 7; $i > 0 && $i < 7; $i++)
        <div class="day is-other"></div>
    @endfor
</div>
