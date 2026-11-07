{{-- The text of a note with its inline lists (NoteText). A click on an entry ticks it; app.js sends it. --}}
@php($listNo = 0)
@foreach (\App\Domain\Notes\NoteText::parse((string) $note->body) as $segment)
    @if ($segment['type'] === 'text')
        <p class="note-body">{{ $segment['text'] }}</p>
    @else
        <ul class="note-checklist">
            @foreach ($segment['items'] as $i => $item)
                <li><label><input type="checkbox" @checked($item['done']) @disabled(! $open)
                    @if ($open) data-note-check="{{ route('notes.check', $note->id) }}" data-list="{{ $listNo }}" data-item="{{ $i }}" data-text="{{ $item['text'] }}" @endif>
                    <span>{{ $item['text'] }}</span></label></li>
            @endforeach
        </ul>
        @php($listNo++)
    @endif
@endforeach
