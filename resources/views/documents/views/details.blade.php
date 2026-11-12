<div class="view-details">
    <table>
        <thead>
            <tr>
                <th scope="col">{{ __('documents.name') }}</th>
                <th scope="col">{{ __('documents.date') }}</th>
                <th scope="col">{{ __('documents.tags') }}</th>
                <th scope="col"><span class="visually-hidden">{{ __('folders.properties') }}</span></th>
            </tr>
        </thead>
        <tbody>
            {{-- data-doc: a row that can be picked (Ctrl/Shift, A132) and dragged onto a folder row (point 75). --}}
            @foreach ($documents as $document)
                <tr data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc="{{ $document->id }}" data-folder="{{ $document->folder_id }}" @if ($open?->id === $document->id) class="is-open" @endif>
                    <td><a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window>@include('documents._type-icon')<span data-doc-title>{{ $document->title }}</span></a></td>
                    <td>{{ $document->created_at->format('d.m.Y H:i') }}</td>
                    <td>@include('documents._tags')</td>
                    <td class="row-actions-cell">@include('documents._row-actions')</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
