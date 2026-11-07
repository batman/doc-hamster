@php($key = $note?->id ?? 'new')
<label for="title-{{ $key }}">{{ __('home.note_title') }}</label>
<input id="title-{{ $key }}" name="title" maxlength="100" value="{{ $note?->title }}" placeholder="{{ __('home.untitled') }}">

{{-- The text is written here only for a new note; afterwards right on the note. --}}
@if ($note === null)
    <label for="body-{{ $key }}">{{ __('home.body') }}</label>
    <textarea id="body-{{ $key }}" class="note-new-text" name="body" rows="4"></textarea>
@else
    <p class="note-hint">{{ __('home.text_hint') }}</p>
@endif
<p class="note-hint">{{ __('home.list_hint') }}</p>

<label for="link-{{ $key }}">{{ __('home.link') }}</label>
<input id="link-{{ $key }}" name="link" list="note-documents" autocomplete="off" placeholder="{{ __('home.link_hint') }}">
@if ($note?->documents->isNotEmpty())
    @foreach ($note->documents as $document)
        <label><input type="checkbox" name="unlink[]" value="{{ $document->id }}"> {{ __('home.unlink', ['title' => $document->title]) }}</label>
    @endforeach
@endif

{{-- Tags like on receipts (A19): only existing ones, new tags are made on the Tags page (A20). --}}
@if ($tags->isNotEmpty())
    <fieldset class="note-tag-field">
        <legend>{{ __('home.tags') }}</legend>
        <div class="tags">
            @foreach ($tags as $tag)
                <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked($note?->tags->contains('id', $tag->id))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
            @endforeach
        </div>
    </fieldset>
@endif

{{-- Moving without dragging (phones, A36): the note goes to the end of the chosen column. --}}
@if ($note !== null && $columns->count() > 1)
    @php($inColumn = $columns->contains('id', $note->column_id) ? $note->column_id : $columns->first()?->id)
    <label for="column-{{ $key }}">{{ __('home.column') }}</label>
    <select id="column-{{ $key }}" name="column">
        @foreach ($columns as $column)
            <option value="{{ $column->id }}" @selected($column->id === $inColumn)>{{ $column->name }}</option>
        @endforeach
    </select>
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
    {{-- A new note is for everyone and changeable by default (Go of 2026-11-07). --}}
    <label><input type="checkbox" name="shared" value="1" @checked($note?->shared ?? true)> {{ __('home.share') }}</label>
    <label><input type="checkbox" name="editable" value="1" @checked($note?->editable ?? true)> {{ __('home.let_edit') }}</label>
@endif
