{{-- Row actions of a document row ("Zeilen-Aktionen", A174, A177): pencil = rename in place, gear = open its details on the right. --}}
<span class="row-actions">
    @can('documents.edit')
        <button type="button" class="control-button" data-doc-rename="{{ route('documents.field', $document->id) }}" aria-label="{{ __('documents.bulk.rename') }}: {{ $document->title }}" data-tooltip="{{ __('documents.bulk.rename') }}"><x-icon name="pencil" /></button>
    @endcan
    <a class="control-button" href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window aria-label="{{ __('documents.bulk.details') }}: {{ $document->title }}" data-tooltip="{{ __('documents.bulk.details') }}"><x-icon name="settings" /></a>
</span>
