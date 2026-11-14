{{-- Fällige Rechnungen (A349): unpaid documents with a due date, the nearest first, with the amount. --}}
@if ($rows === [])
    <p class="list-empty">{{ __('dashboard.due_empty') }}</p>
@else
    <ul class="pick-list dash-list">
        @foreach ($rows as $row)
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon name="file-invoice" /></span>
                <span><strong><a href="{{ route('documents.show', $row['document']->id) }}" data-window>{{ $row['document']->title }}</a></strong><small>{{ __('dashboard.due_on', ['date' => $row['due']->format('d.m.Y')]) }}</small></span>
                @if ($row['overdue'])<span class="pill pill-warning">{{ __('dashboard.overdue') }}</span>@endif
                @if ($row['amount'] !== null)<span class="row-amount">{{ $row['amount'] }}</span>@endif
            </li>
        @endforeach
    </ul>
@endif
