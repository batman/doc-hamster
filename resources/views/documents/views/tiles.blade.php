{{-- Only large icons show the page itself; small icons show the file type (design point 13). --}}
<ul class="view-tiles view-{{ $view }}">
    @foreach ($documents as $document)
        <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc="{{ $document->id }}" data-folder="{{ $document->folder_id }}" @if ($open?->id === $document->id) class="is-open" @endif>
            <a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window>
                @if ($view === 'large')
                    @include('documents._thumb')
                @else
                    <span class="thumb-frame"><x-icon :name="$document->files->first()?->typeIcon() ?? 'file-text'" /></span>
                @endif
                <span class="name">{{ $document->title }}</span>
                <span class="muted">{{ $document->created_at->format('d.m.Y') }}</span>
            </a>
            @if ($searching)
                @include('documents._folder-link')
            @endif
        </li>
    @endforeach
</ul>
