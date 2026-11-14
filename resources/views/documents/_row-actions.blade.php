{{-- Row actions of a document row ("Zeilen-Aktionen", A174, A177): pencil (or a long press on the name, A190) = rename in place, gear = open its details on the right.
     $inboxDone (Posteingang, point 153): first a ✓ "Erledigt" that takes the row out of the inbox. --}}
<span class="row-actions">
    @if ($inboxDone ?? false)
        <form method="post" action="{{ route('inbox.done', $document->id) }}" data-inbox-action>
            @csrf
            <button type="submit" class="control-button" aria-label="{{ __('inbox.done') }}: {{ $document->title }}" data-tooltip="{{ __('inbox.done') }}"><x-icon name="check" /></button>
        </form>
    @endif
    @can('documents.edit')
        <button type="button" class="control-button" data-doc-rename="{{ route('documents.field', $document->id) }}" data-save="{{ __('documents.save') }}" aria-label="{{ __('documents.bulk.rename') }}: {{ $document->title }}" data-tooltip="{{ __('documents.bulk.rename') }}"><x-icon name="pencil" /></button>
    @endcan
    <a class="control-button" href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window aria-label="{{ __('documents.bulk.details') }}: {{ $document->title }}" data-tooltip="{{ __('documents.bulk.details') }}"><x-icon name="settings" /></a>
</span>
