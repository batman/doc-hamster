<ul class="view-list">
    @foreach ($documents as $document)
        <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}">
            <x-icon name="file-text" />
            <a href="{{ $document->fileUrl() }}">{{ $document->title }}</a>
            <span class="muted">{{ $document->created_at->format('d.m.Y') }}</span>
        </li>
    @endforeach
</ul>
