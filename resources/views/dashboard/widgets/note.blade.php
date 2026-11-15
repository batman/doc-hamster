{{-- A pinned note (A396): the whole text in the note's colour. Whoever may change it writes right here; the text saves
     at once through the notes board's own address (notes.text, dashboard.js). --}}
<div class="dash-note {{ $note->colorClass() }}">
    @if ($editable)
        <textarea class="dash-note-text" rows="4" spellcheck="false" aria-label="{{ __('dashboard.note_text') }}" data-dash-note-text="{{ route('notes.text', $note->id) }}">{{ $note->body }}</textarea>
    @else
        <p class="dash-note-text">{{ $note->body }}</p>
    @endif
</div>
<p class="dash-more"><a href="{{ route('notes.index', ['note' => $note->id]) }}" data-window>{{ __('dashboard.note_open') }}</a></p>
