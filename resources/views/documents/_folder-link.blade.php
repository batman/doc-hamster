{{-- The folder a search result lies in (A204): a quiet link that opens the folder as a window on the right. --}}
@php($inFolder = $document->folder)
<a class="doc-folder" href="{{ route('documents.index', $inFolder ? ['folder' => $inFolder->id] : []) }}" data-window><x-icon :name="$inFolder?->iconName() ?? 'folder'" /><span>{{ $inFolder?->name ?? __('documents.title') }}</span></a>
