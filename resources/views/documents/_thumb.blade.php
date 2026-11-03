@php($file = $document->files->first())
@if ($file?->isImage())
    <img class="thumb" src="{{ route('documents.file', $file->id) }}" alt="" loading="lazy">
@else
    <span class="thumb thumb-file"><x-icon name="file-text" /></span>
@endif
