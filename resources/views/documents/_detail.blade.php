{{-- The receipt as a window right of the list (window system, A76). Its dialogs live inside the window, so
     they come along when it is loaded in the background; their names carry the receipt's id.
     The document is only shown, never opened by a click: downloads go through the button. --}}
@php
    $file = $document->files->first();
    $close = route('documents.index', request()->query());
    $when = fn ($date) => $date?->format('d.m.Y H:i') ?? __('documents.never');
    $booking = $document->matches->map(fn ($match) => $match->transaction->booking_date->format('d.m.Y').' · '.$match->transaction->formattedAmount())->implode(', ');
    $shareUrl = session('share_url');
@endphp
<x-window :title="$document->title" icon="file-text" :url="route('documents.show', [$document->id, ...request()->query()])" :close="$close" class="win-detail">
    <div class="detail-actions">
        @if ($file)
            <a class="icon-button" href="{{ route('documents.download', $file->id) }}" aria-label="{{ __('documents.download') }}" data-tooltip="{{ __('documents.download') }}"><x-icon name="download" /></a>
        @endif
        @can('documents.edit')
            <a class="icon-button" href="#title-{{ $document->id }}" aria-label="{{ __('documents.edit') }}" data-tooltip="{{ __('documents.edit') }}"><x-icon name="pencil" /></a>
        @endcan
        @if ($file)
            {{-- The device's share menu with the file itself (A2); where there is none, our dialog with mail and link (A3). --}}
            <button type="button" class="icon-button" aria-label="{{ __('documents.send') }}" data-tooltip="{{ __('documents.send') }}"
                data-share="{{ route('documents.download', $file->id) }}" data-share-name="{{ $document->title }}" data-share-type="{{ $file->mime }}" data-dialog-open="share-document-{{ $document->id }}"><x-icon name="send" /></button>
        @endif
        @can('documents.trash')
            <button type="button" class="icon-button" aria-label="{{ __('documents.delete') }}" data-tooltip="{{ __('documents.delete') }}" data-dialog-open="delete-document-{{ $document->id }}"><x-icon name="trash" /></button>
        @endcan
    </div>

    @if ($file?->isImage())
        <span class="thumb-frame detail-doc"><img class="thumb" src="{{ route('documents.file', $file->id) }}" alt="" draggable="false"></span>
    @elseif ($file)
        @include('documents._thumb', ['frameClass' => 'detail-doc'])
    @endif

    @can('documents.edit')
        <form method="post" action="{{ route('documents.update', $document->id) }}">
            @csrf
            <section class="detail-section">
                <h3><label for="title-{{ $document->id }}">{{ __('documents.name') }}</label></h3>
                <input id="title-{{ $document->id }}" name="title" value="{{ old('title', $document->title) }}" maxlength="250" required>
            </section>
            <section class="detail-section">
                <h3><label for="folder-{{ $document->id }}">{{ __('folders.folder') }}</label></h3>
                <select id="folder-{{ $document->id }}" name="folder">
                    @foreach ($folders as $row)
                        <option value="{{ $row['folder']->id }}" @selected(old('folder', $document->folder_id) === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                    @endforeach
                </select>
            </section>
            @if ($tags->isNotEmpty())
                <section class="detail-section">
                    <h3>{{ __('documents.tags') }}</h3>
                    <div class="tags">
                        @foreach ($tags as $tag)
                            <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked($document->tags->contains('id', $tag->id))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
                        @endforeach
                    </div>
                </section>
            @endif
            <section class="detail-section">
                <h3><label for="note-{{ $document->id }}">{{ __('documents.note') }}</label></h3>
                <textarea id="note-{{ $document->id }}" name="note" placeholder="{{ __('documents.note_placeholder') }}">{{ old('note', $document->note) }}</textarea>
                <button type="submit">{{ __('documents.save') }}</button>
            </section>
        </form>
    @else
        <section class="detail-section">
            <h3>{{ __('documents.tags') }}</h3>
            @include('documents._tags')
        </section>
        @if ($document->note)
            <section class="detail-section"><h3>{{ __('documents.note') }}</h3><p>{{ $document->note }}</p></section>
        @endif
    @endcan

    <section class="detail-section">
        <h3>{{ __('documents.history') }}</h3>
        <dl class="detail-facts">
            <dt>{{ __('documents.facts.uploaded') }}</dt><dd>{{ $when($document->created_at) }}</dd>
            <dt>{{ __('documents.facts.created') }}</dt><dd>{{ $document->doc_date?->format('d.m.Y') ?? '–' }}</dd>
            <dt>{{ __('documents.facts.opened') }}</dt><dd>{{ $when($document->last_opened_at) }}</dd>
            <dt>{{ __('documents.facts.downloaded') }}</dt><dd>{{ $when($document->last_downloaded_at) }}</dd>
            <dt>{{ __('documents.facts.type') }}</dt><dd>{{ $file?->mime ?? '–' }}</dd>
            <dt>{{ __('documents.facts.booking') }}</dt><dd>{{ $booking !== '' ? $booking : '–' }}</dd>
        </dl>
    </section>

@if ($file)
    <div class="dialog-backdrop" data-dialog="share-document-{{ $document->id }}" @unless ($shareUrl) hidden @endunless>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="share-document-{{ $document->id }}-title">
            <h2 id="share-document-{{ $document->id }}-title">{{ __('documents.send') }}</h2>
            @if ($shareUrl)
                <label for="share-url-{{ $document->id }}" class="visually-hidden">{{ __('documents.share_copy') }}</label>
                <input id="share-url-{{ $document->id }}" value="{{ $shareUrl }}" readonly data-share-url>
                <div class="dialog-actions">
                    <a class="button button-quiet" href="mailto:?subject={{ rawurlencode(__('documents.share_mail_subject', ['title' => $document->title])) }}&amp;body={{ rawurlencode($shareUrl) }}">{{ __('documents.share_mail') }}</a>
                    <button type="button" data-copy="share-url-{{ $document->id }}" data-copied="{{ __('documents.share_copied') }}">{{ __('documents.share_copy') }}</button>
                </div>
            @else
                @can('exports.create')
                <form method="post" action="{{ route('documents.share', $document->id) }}">
                    @csrf
                    <div class="dialog-actions">
                        <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                        <button type="submit">{{ __('documents.share_link') }}</button>
                    </div>
                </form>
                @else
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                    <a class="button" href="{{ route('documents.download', $file->id) }}">{{ __('documents.download') }}</a>
                </div>
                @endcan
            @endif
        </div>
    </div>
@endif

@can('documents.trash')
    <div class="dialog-backdrop" data-dialog="delete-document-{{ $document->id }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-document-{{ $document->id }}-title">
            <h2 id="delete-document-{{ $document->id }}-title">{{ __('documents.delete_title') }}</h2>
            <p>{{ __('documents.delete_text', ['title' => $document->title]) }}</p>
            <div class="dialog-actions">
                <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                <form method="post" action="{{ route('documents.destroy', $document->id) }}">
                    @csrf
                    <button type="submit" class="button-danger">{{ __('documents.delete') }}</button>
                </form>
            </div>
        </div>
    </div>
@endcan
</x-window>
