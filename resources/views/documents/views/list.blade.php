<ul class="view-list">
    @foreach ($documents as $document)
        <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" @if ($open?->id === $document->id) class="is-open" @endif>
            @include('documents._type-icon')
            <a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window>{{ $document->title }}</a>
            <span class="muted">{{ $document->created_at->format('d.m.Y') }}</span>
        </li>
    @endforeach
</ul>
