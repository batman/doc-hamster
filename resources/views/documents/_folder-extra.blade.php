{{-- Point 30: what a folder row adds in the views "Details" (columns: documents, subfolders, last change) and
     "Vorschau" (the small pictures of its newest documents); hidden in the other views (css/modules/documents.css). --}}
@if ($view === 'details')
    <span class="folder-cols">
        <span>{{ $documents }}</span>
        <span>{{ $folders }}</span>
        <span>{{ $changed !== null ? \Illuminate\Support\Carbon::parse($changed)->format('d.m.Y') : '–' }}</span>
    </span>
@elseif ($view === 'large' && $previews !== [])
    <span class="folder-previews" aria-hidden="true">
        @foreach ($previews as $src)
            <span class="thumb-frame"><img class="thumb" src="{{ $src }}" alt="" loading="lazy" draggable="false"></span>
        @endforeach
    </span>
@endif
