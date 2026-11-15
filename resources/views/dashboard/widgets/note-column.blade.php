{{-- A pinned notes column (point 233, A395): its notes as coloured rows (colour + title or first line); a click opens
     the notes window scrolled to that note, which lights up (notes.js, ?note=). --}}
@if ($notes->isEmpty())
    <x-empty icon="note" :text="__('dashboard.note_column_empty')" />
@else
    <ul class="pick-list dash-list">
        @foreach ($notes as $note)
            @php
                $line = trim((string) collect(preg_split('/\R/u', (string) $note->body) ?: [])->first(static fn (string $l): bool => trim($l) !== ''));
                $label = $note->title ?: ($line !== '' ? \Illuminate\Support\Str::limit($line, 80) : __('home.untitled'));
            @endphp
            <li class="pick-row is-static row-color {{ $note->colorClass() }}">
                <span class="row-icon"><x-icon name="note" /></span>
                <span><strong><a href="{{ route('notes.index', ['note' => $note->id]) }}" data-window>{{ $label }}</a></strong></span>
            </li>
        @endforeach
    </ul>
@endif
<p class="dash-more"><a href="{{ route('notes.index') }}" data-window>{{ __('dashboard.notes_all') }}</a></p>
