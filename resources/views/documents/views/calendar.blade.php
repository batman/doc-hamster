{{-- One month, the week starts on Monday; every document of a day is listed, by the chosen date (points 101, 108, A205).
     Paging only between the months of the earliest and the latest document (point 107). --}}
@php($byDay = $documents->groupBy(fn ($document) => $document->dateBy($dates)->day))
<div class="calendar-head">
    <h2>{{ $month->locale(app()->getLocale())->translatedFormat('F Y') }}</h2>
    @foreach ([[-1, 'chevron-left', 'previous_month'], [1, 'chevron-right', 'next_month']] as [$step, $icon, $label])
        @php($target = $month->addMonths($step))
        @if ($range !== null && $target->betweenIncluded($range['first'], $range['last']))
            <a class="icon-button" href="{{ request()->fullUrlWithQuery(['month' => $target->format('Y-m')]) }}" aria-label="{{ __('documents.'.$label) }}" data-tooltip="{{ __('documents.'.$label) }}"><x-icon :name="$icon" /></a>
        @else
            <button type="button" class="icon-button" disabled aria-label="{{ __('documents.'.$label) }}"><x-icon :name="$icon" /></button>
        @endif
    @endforeach
</div>
<div class="calendar">
    @foreach (__('documents.weekdays') as $weekday)
        <div class="weekday">{{ $weekday }}</div>
    @endforeach
    @for ($i = 1; $i < $month->dayOfWeekIso; $i++)
        <div class="day is-other"></div>
    @endfor
    @for ($day = 1; $day <= $month->daysInMonth; $day++)
        <div class="day">
            <b>{{ $day }}</b>
            @foreach ($byDay->get($day, collect()) as $document)
                <a class="entry" href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}">{{ $document->title }}</a>
            @endforeach
        </div>
    @endfor
    @for ($i = ($month->dayOfWeekIso - 1 + $month->daysInMonth) % 7; $i > 0 && $i < 7; $i++)
        <div class="day is-other"></div>
    @endfor
</div>
