@if ($document->tags->isNotEmpty())
    <ul class="tags">
        @foreach ($document->tags as $tag)
            <li><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></li>
        @endforeach
    </ul>
@endif
