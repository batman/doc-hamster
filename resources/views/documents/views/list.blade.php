<ul class="view-list">
    @foreach ($documents as $document)
        <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc="{{ $document->id }}" data-folder="{{ $document->folder_id }}" @if ($open?->id === $document->id) class="is-open" @endif>
            @include('documents._type-icon')
            <a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window><span data-doc-title>{{ $document->title }}</span></a>
            <span class="muted">{{ $document->created_at->format('d.m.Y') }}</span>
            @include('documents._row-actions')
        </li>
    @endforeach
</ul>
