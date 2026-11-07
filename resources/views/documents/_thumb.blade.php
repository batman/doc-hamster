{{-- Never cropped: the whole page in a frame. Only the stored small copy, never the original (design points 14, 15). --}}
@php($file = $document->files->first())
<span class="thumb-frame {{ $frameClass ?? '' }}">
    @if ($file?->thumb_path)
        <img class="thumb" src="{{ route('documents.thumb', $file->id) }}" alt="" loading="lazy" draggable="false">
    @else
        <x-icon :name="$file?->typeIcon() ?? 'file-text'" />
    @endif
</span>
