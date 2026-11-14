{{-- Notizen (A349): notes have no pin, so the person's three newest; the board is /notes. --}}
@if ($notes->isEmpty())
    <p class="list-empty">{{ __('dashboard.notes_empty') }}</p>
@else
    <ul class="pick-list dash-list">
        @foreach ($notes as $note)
            @php $text = trim((string) preg_replace('/\s+/u', ' ', (string) $note->body)); @endphp
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon name="note" /></span>
                <span><strong><a href="{{ route('notes.index') }}" data-window>{{ $note->title ?: \Illuminate\Support\Str::limit($text !== '' ? $text : __('home.untitled'), 60) }}</a></strong>@if ($note->title && $text !== '')<small>{{ \Illuminate\Support\Str::limit($text, 80) }}</small>@endif</span>
            </li>
        @endforeach
    </ul>
@endif
<p class="dash-more"><a href="{{ route('notes.index') }}" data-window>{{ __('dashboard.notes_all') }}</a></p>
