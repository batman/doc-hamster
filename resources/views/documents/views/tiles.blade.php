<ul class="view-tiles view-{{ $view }}">
    @foreach ($documents as $document)
        <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}">
            <a href="{{ $document->fileUrl() }}">
                @include('documents._thumb')
                <span class="name">{{ $document->title }}</span>
                <span class="muted">{{ $document->created_at->format('d.m.Y') }}</span>
            </a>
        </li>
    @endforeach
</ul>
