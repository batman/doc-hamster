{{-- Letzte Aktivitäten (A349): the person's own events (uploaded, moved, paid, linked); each opens its document where it can. --}}
@if ($rows === [])
    <p class="list-empty">{{ __('dashboard.activity_empty') }}</p>
@else
    <ul class="pick-list dash-list">
        @foreach ($rows as $row)
            @php
                $href = $row['document'] !== null ? route('documents.show', $row['document']) : ($row['bank'] && Gate::allows('banking.import') ? route('bank.index') : null);
            @endphp
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon :name="$row['icon']" /></span>
                <span><strong>@if ($href)<a href="{{ $href }}" data-window>{{ $row['message'] }}</a>@else{{ $row['message'] }}@endif</strong><small>{{ $row['at']->locale(app()->getLocale())->diffForHumans() }}</small></span>
            </li>
        @endforeach
    </ul>
@endif
