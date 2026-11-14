{{-- Point 166: file rows look like the folder rows above them – Listeneintrag with fill, the orange file-type symbol in the
     row-icon slot, the title and a second line (date · size/pages · tags). The whole row opens the document (point 167):
     the title link stretches over the row (lists.css); row tools keep their own action. --}}
<ul class="pick-list doc-rows">
    @foreach ($documents as $document)
        @php
            $bytes = (int) $document->files->sum('size');
            $size = $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : max(1, (int) round($bytes / 1024)).' KB';
            $pages = $document->files->count();
        @endphp
        <li class="pick-row is-static doc-row @if ($open?->id === $document->id) is-open @endif" data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc="{{ $document->id }}" data-doc-file="{{ $document->dragFile() }}" data-folder="{{ $document->folder_id }}" data-doc-row tabindex="0">
            <span class="row-icon"><x-icon :name="$document->files->first()?->typeIcon() ?? 'file-text'" /></span>
            <span class="doc-row-text">
                <strong><a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window tabindex="-1"><span data-doc-title>{{ $document->title }}</span></a></strong>
                <small class="row-stats">
                    <span>{{ $document->created_at->format('d.m.Y') }}</span>
                    @if ($bytes > 0)
                        <span class="row-stat-sep" aria-hidden="true">·</span><span>{{ $size }}</span>
                    @endif
                    @if ($pages > 1)
                        <span class="row-stat-sep" aria-hidden="true">·</span><span>{{ trans_choice('documents.page_count', $pages, ['count' => $pages]) }}</span>
                    @endif
                    @if ($document->tags->isNotEmpty())
                        <span class="row-stat-sep" aria-hidden="true">·</span>
                        @foreach ($document->tags as $tag)<span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span>@endforeach
                    @endif
                </small>
            </span>
            @if ($searching)
                @include('documents._folder-link')
            @endif
            @include('documents._row-actions')
        </li>
    @endforeach
</ul>
