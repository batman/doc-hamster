@php($key = $note?->id ?? 'new')
<label for="body-{{ $key }}">{{ __('home.body') }}</label>
<textarea id="body-{{ $key }}" name="body" rows="3">{{ $note?->body }}</textarea>

<label for="checklist-{{ $key }}">{{ __('home.checklist') }}</label>
<textarea id="checklist-{{ $key }}" name="checklist" rows="3" placeholder="{{ __('home.checklist_hint') }}">{{ collect($note?->checklist ?? [])->map(fn ($item) => ($item['done'] ? '[x] ' : '').$item['text'])->implode("\n") }}</textarea>

<label for="link-{{ $key }}">{{ __('home.link') }}</label>
<input id="link-{{ $key }}" name="link" list="note-documents" autocomplete="off" placeholder="{{ __('home.link_hint') }}">
@if ($note?->documents->isNotEmpty())
    @foreach ($note->documents as $document)
        <label><input type="checkbox" name="unlink[]" value="{{ $document->id }}"> {{ __('home.unlink', ['title' => $document->title]) }}</label>
    @endforeach
@endif

@if ($canShare && ($note === null || $note->user_id === $userId))
    <label><input type="checkbox" name="shared" value="1" @checked($note?->shared)> {{ __('home.share') }}</label>
    <label><input type="checkbox" name="editable" value="1" @checked($note?->editable)> {{ __('home.let_edit') }}</label>
@endif
