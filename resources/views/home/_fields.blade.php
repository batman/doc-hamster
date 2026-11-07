@php($key = $note?->id ?? 'new')
<label for="title-{{ $key }}">{{ __('home.note_title') }}</label>
<input id="title-{{ $key }}" name="title" maxlength="100" value="{{ $note?->title }}" placeholder="{{ __('home.untitled') }}">

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

<fieldset class="note-color-field">
    <legend>{{ __('home.color') }}</legend>
    @foreach (\App\Domain\Notes\Note::COLORS as $choice)
        <label class="note-swatch-choice"><input type="radio" class="visually-hidden" name="color" value="{{ $choice }}" @checked(($note?->color ?? 'yellow') === $choice)><span class="note-swatch note-{{ $choice }}" title="{{ __('home.colors.'.$choice) }}"></span><span class="visually-hidden">{{ __('home.colors.'.$choice) }}</span></label>
    @endforeach
</fieldset>

<label for="opacity-{{ $key }}">{{ __('home.opacity') }}</label>
<input id="opacity-{{ $key }}" class="note-opacity" type="range" name="opacity" min="30" max="100" step="5" value="{{ $note?->opacity ?? 100 }}">

@if ($canShare && ($note === null || $note->user_id === $userId))
    <label><input type="checkbox" name="shared" value="1" @checked($note?->shared)> {{ __('home.share') }}</label>
    <label><input type="checkbox" name="editable" value="1" @checked($note?->editable)> {{ __('home.let_edit') }}</label>
@endif
