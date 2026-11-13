{{-- Point 113: no own scroll box – the table always fits the window; A222: the Tags column only when a row has tags;
     A220: narrow windows drop Tags first, then the time (container queries in css/modules/documents.css). --}}
@php $withTags = $documents->contains(fn ($d) => $d->tags->isNotEmpty()); @endphp
<div class="view-details">
    <table>
        <thead>
            <tr>
                <th scope="col">{{ __('documents.name') }}</th>
                @if ($searching)
                    <th scope="col" class="col-folder">{{ __('documents.folder') }}</th>
                @endif
                <th scope="col" class="col-date">{{ __('documents.date') }}</th>
                @if ($withTags)
                    <th scope="col" class="col-tags">{{ __('documents.tags') }}</th>
                @endif
                <th scope="col" class="col-actions"><span class="visually-hidden">{{ __('folders.properties') }}</span></th>
            </tr>
        </thead>
        <tbody>
            {{-- data-doc: a row that can be picked (Ctrl/Shift, A132) and dragged onto a folder row (point 75). --}}
            @foreach ($documents as $document)
                <tr data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc="{{ $document->id }}" data-folder="{{ $document->folder_id }}" @if ($open?->id === $document->id) class="is-open" @endif>
                    <td><a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window>@include('documents._type-icon')<span data-doc-title>{{ $document->title }}</span></a></td>
                    @if ($searching)
                        <td>@include('documents._folder-link')</td>
                    @endif
                    <td class="col-date">{{ $document->created_at->format('d.m.Y') }}<span class="col-time"> {{ $document->created_at->format('H:i') }}</span></td>
                    @if ($withTags)
                        <td class="col-tags">@include('documents._tags')</td>
                    @endif
                    <td class="row-actions-cell">@include('documents._row-actions')</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
