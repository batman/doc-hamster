@php($file = $document->files->first())
<li>
    @if ($file)
        <a href="{{ route('documents.file', $file->id) }}">
            @if ($file->isImage())
                <img src="{{ route('documents.file', $file->id) }}" alt="" loading="lazy">
            @endif
            {{ $document->title }}
        </a>
    @else
        {{ $document->title }}
    @endif
    <span class="muted">{{ $document->created_at->format('d.m.Y H:i') }}</span>
    {{ $slot ?? '' }}
</li>
