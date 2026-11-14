{{-- Selection bar of a list window (point 196, A369, A370): the docked Auswahl-Leiste, shown while rows are picked by their
     checkbox (documents/_pick), Ctrl or Shift. "N ausgewählt", the Ordner-Menü (moves / files every picked row), in the
     Posteingang ✓ "Erledigt" ($doneUrl), X clears. JS: documents-select.js (count), documents-pick.js (actions).
     Needs the window's Ordner-Menü template (inbox/_folder-menu). --}}
<div class="selection-bar is-docked" role="toolbar" aria-label="{{ __('documents.pick.bar') }}" data-pick-bar hidden>
    <span class="selection-count" data-pick-count data-label="{{ __('documents.pick.count') }}" aria-live="polite"></span>
    <div class="dropdown">
        @php $moveLabel = ($doneUrl ?? null) !== null ? __('inbox.file_into') : __('documents.pick.move'); @endphp
        <button type="button" class="control-button" aria-expanded="false" data-folder-menu aria-label="{{ $moveLabel }}" data-tooltip="{{ $moveLabel }}"><x-icon name="folder" /></button>
        <div class="dropdown-panel folder-menu" hidden></div>
    </div>
    @if (($doneUrl ?? null) !== null)
        <button type="button" class="control-button" data-pick-done="{{ $doneUrl }}" aria-label="{{ __('inbox.done') }}" data-tooltip="{{ __('inbox.done') }}"><x-icon name="check" /></button>
    @endif
    <button type="button" class="control-button" data-pick-clear aria-label="{{ __('documents.pick.clear') }}" data-tooltip="{{ __('documents.pick.clear') }}"><x-icon name="x" /></button>
</div>
