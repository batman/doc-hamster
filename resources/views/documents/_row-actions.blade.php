{{-- Row actions of a document row ("Zeilen-Aktionen", A174, A177): pencil (or a long press on the name, A190) = rename in place.
     No gear any more (point 167): the whole row opens the document.
     $inboxDone (Posteingang, point 153): first the folder menu "Ablegen in …" (A360, filled by inbox.js) and a ✓ "Erledigt"
     that files the row into "Dokumente allgemein" (A358). --}}
<span class="row-actions">
    @if (($inboxDone ?? false) && Gate::allows('documents.edit'))
        <div class="dropdown">
            <button type="button" class="control-button" aria-expanded="false" data-folder-menu aria-label="{{ __('inbox.file_into') }}: {{ $document->title }}" data-tooltip="{{ __('inbox.file_into') }}"><x-icon name="folder" /></button>
            <div class="dropdown-panel folder-menu" hidden></div>
        </div>
    @endif
    @if ($inboxDone ?? false)
        <form method="post" action="{{ route('inbox.done', $document->id) }}" data-inbox-action>
            @csrf
            <button type="submit" class="control-button" aria-label="{{ __('inbox.done') }}: {{ $document->title }}" data-tooltip="{{ __('inbox.done') }}"><x-icon name="check" /></button>
        </form>
    @endif
    @can('documents.edit')
        <button type="button" class="control-button" data-doc-rename="{{ route('documents.field', $document->id) }}" data-save="{{ __('documents.save') }}" aria-label="{{ __('documents.bulk.rename') }}: {{ $document->title }}" data-tooltip="{{ __('documents.bulk.rename') }}"><x-icon name="pencil" /></button>
    @endcan
</span>
