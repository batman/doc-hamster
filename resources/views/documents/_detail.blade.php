{{-- The receipt as a window right of the list (window system, A76). Its dialogs live inside the window, so
     they come along when it is loaded in the background; their names carry the receipt's id.
     The document is only shown, never opened by a click: downloads go through the button. --}}
@php
    $file = $document->files->first();
    $close = route('documents.index', request()->query());
    $when = fn ($date) => $date?->format('d.m.Y H:i') ?? __('documents.never');
    $booking = $document->matches->map(fn ($match) => $match->transaction->booking_date->format('d.m.Y').' · '.$match->transaction->formattedAmount())->implode(', ');
    $shareUrl = session('share_url');
    $isText = \App\Domain\Documents\TextDocument::isText($file);
    // Text recognition state (A23): running wins over the stored status.
    $ocrRunning = (\App\Infrastructure\Environment\OcrProgress::current($document->org_id)['id'] ?? null) === $document->id;
    $ocrState = $ocrRunning ? 'running' : match ($document->ocr_status) {
        'done' => 'done',
        'failed' => 'failed',
        'unavailable' => 'unavailable',
        default => 'waiting',
    };
@endphp
<x-window :title="$document->title" icon="file-text" kind="detail" :url="route('documents.show', [$document->id, ...request()->query()])" :close="$close" class="win-detail">
    {{-- Only "Herunterladen" stays a button of its own; everything else is in the ⋯ menu (point 26). --}}
    @php
        $canEdit = Gate::allows('documents.edit');
        $canTrash = Gate::allows('documents.trash');
    @endphp
    <div class="detail-actions">
        @if ($file)
            <a class="icon-button" href="{{ route('documents.download', $file->id) }}" aria-label="{{ __('documents.download') }}" data-tooltip="{{ __('documents.download') }}"><x-icon name="download" /></a>
        @endif
        @if ($isText || $canEdit || $file || $canTrash)
            <x-more-menu>
                @if ($isText)
                    {{-- Text documents open in their own editor window (point 21); without documents.edit it only reads. --}}
                    <a class="menu-item" href="{{ route('documents.text', $document->id) }}" data-window><x-icon name="pencil" /><span>{{ $canEdit ? __('documents.edit') : __('documents.text.open') }}</span></a>
                @elseif ($canEdit)
                    <a class="menu-item" href="#title-{{ $document->id }}" data-focus="title-{{ $document->id }}"><x-icon name="pencil" /><span>{{ __('documents.edit') }}</span></a>
                @endif
                @if ($file)
                    {{-- The device's share menu with the file itself (A2); where there is none, our dialog with mail and link (A3). --}}
                    <button type="button" class="menu-item" data-share="{{ route('documents.download', $file->id) }}" data-share-name="{{ $document->title }}" data-share-type="{{ $file->mime }}" data-dialog-open="share-document-{{ $document->id }}"><x-icon name="send" /><span>{{ __('documents.send') }}</span></button>
                @endif
                @if ($canEdit)
                    <button type="button" class="menu-item" data-dialog-open="move-document-{{ $document->id }}"><x-icon name="arrows-move" /><span>{{ __('documents.move') }}</span></button>
                @endif
                @if ($canTrash)
                    <hr class="menu-separator">
                    <button type="button" class="menu-item menu-danger" data-dialog-open="delete-document-{{ $document->id }}"><x-icon name="trash" /><span>{{ __('documents.delete') }}</span></button>
                @endif
            </x-more-menu>
        @endif
    </div>

    @if ($file?->isImage())
        <span class="thumb-frame detail-doc"><img class="thumb" src="{{ route('documents.file', $file->id) }}" alt="" draggable="false"></span>
    @elseif ($isText)
        {{-- Formatted text is sanitized again before it is shown (TextDocument::display). --}}
        @php $textContent = \App\Domain\Documents\TextDocument::display($file); @endphp
        @if (\App\Domain\Documents\TextDocument::isRich($file))
            <div class="rich-text text-doc">{!! $textContent !!}</div>
        @else
            <pre class="plain-text-view text-doc">{{ $textContent }}</pre>
        @endif
    @elseif ($file?->mime === 'application/pdf')
        {{-- PDFs are drawn sharp with pdf.js (app.js "Document viewer"); the preview image stays if that fails (point 33). --}}
        <span class="thumb-frame detail-doc" tabindex="0" data-pdf="{{ route('documents.file', $file->id) }}">
            @if ($file->thumb_path)
                <img class="thumb" src="{{ route('documents.thumb', $file->id) }}" alt="" draggable="false">
            @else
                <x-icon :name="$file->typeIcon()" />
            @endif
        </span>
    @elseif ($file)
        @include('documents._thumb', ['frameClass' => 'detail-doc'])
    @endif
    @if ($file?->isImage() || $file?->thumb_path || $file?->mime === 'application/pdf')
        {{-- Zoom bar for the viewer above (app.js "Document viewer"): wheel, slider, box zoom and box copy. --}}
        <div class="doc-zoom" data-doc-zoom data-label-zoom="{{ __('documents.zoom_in_area') }}" data-label-copy="{{ __('documents.copy_area') }}" data-label-copied="{{ __('documents.copied') }}">
            {{-- Two modes (point 25): Bewegen pans, Markieren draws a box; Bewegen is the default. --}}
            <div class="button-group" role="group" aria-label="{{ __('documents.zoom_mode') }}">
                <button type="button" class="icon-button button-small" aria-pressed="true" data-zoom-mode="move" aria-label="{{ __('documents.mode_move') }}" data-tooltip="{{ __('documents.mode_move') }}"><x-icon name="hand-stop" /></button>
                <button type="button" class="icon-button button-small" aria-pressed="false" data-zoom-mode="select" aria-label="{{ __('documents.mode_select') }}" data-tooltip="{{ __('documents.mode_select') }}"><x-icon name="zoom-in-area" /></button>
            </div>
            <input type="range" min="25" max="400" step="1" value="100" aria-label="{{ __('documents.zoom') }}" data-zoom-range title="{{ __('documents.zoom_reset') }}">
            <button type="button" class="doc-zoom-value" data-zoom-reset aria-label="{{ __('documents.zoom_reset') }}" data-tooltip="{{ __('documents.zoom_reset') }}"><output data-zoom-value>100 %</output></button>
            @if ($file?->mime === 'application/pdf')
                {{-- Pages of a PDF (A19); app.js shows them only when there is more than one. --}}
                <div class="doc-pages" data-doc-pages data-label="{{ __('documents.page_of') }}" hidden>
                    <div class="button-group" role="group" aria-label="{{ __('documents.pages') }}">
                        <button type="button" class="icon-button button-small" data-page-step="-1" aria-label="{{ __('documents.page_prev') }}" data-tooltip="{{ __('documents.page_prev') }}"><x-icon name="chevron-left" /></button>
                        <button type="button" class="icon-button button-small" data-page-step="1" aria-label="{{ __('documents.page_next') }}" data-tooltip="{{ __('documents.page_next') }}"><x-icon name="chevron-right" /></button>
                    </div>
                    <output data-page-label></output>
                </div>
            @endif
        </div>
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
            <button type="submit">{{ __('documents.save') }}</button>
        </form>
        {{-- Note and recognised text are edited in place and save on their own (points 27/28). --}}
        <section class="detail-section">
            <h3 id="note-{{ $document->id }}">{{ __('documents.note') }}</h3>
            <x-edit-in-place name="note" :value="$document->note" :action="route('documents.field', $document->id)" multiline maxlength="5000"
                :placeholder="__('documents.note_placeholder')" aria-labelledby="note-{{ $document->id }}" />
        </section>
        <section class="detail-section">
            <div class="ocr-head">
                <h3 id="ocr-{{ $document->id }}">{{ __('documents.ocr_text') }}</h3>
                @unless ($isText)
                    <span class="tag-outline">{{ __('ocr.state.'.$ocrState) }}</span>
                    @if ($ocrState !== 'running')
                        <form method="post" action="{{ route('ocr.redo', $document->id) }}">
                            @csrf
                            <button type="submit" class="button-quiet button-small">{{ __('ocr.redo') }}</button>
                        </form>
                    @endif
                @endunless
            </div>
            <x-edit-in-place name="ocr_text" :value="$document->ocr_text" :action="route('documents.field', $document->id)" multiline class="edit-in-place-small"
                :placeholder="__('documents.ocr_placeholder')" aria-labelledby="ocr-{{ $document->id }}" />
        </section>
    @else
        <section class="detail-section">
            <h3>{{ __('documents.tags') }}</h3>
            @include('documents._tags')
        </section>
        @if ($document->note)
            <section class="detail-section"><h3>{{ __('documents.note') }}</h3><p>{{ $document->note }}</p></section>
        @endif
        @if ($document->ocr_text || ! $isText)
            <section class="detail-section">
                <div class="ocr-head">
                    <h3>{{ __('documents.ocr_text') }}</h3>
                    @unless ($isText)<span class="tag-outline">{{ __('ocr.state.'.$ocrState) }}</span>@endunless
                </div>
                @if ($document->ocr_text)<p class="edit-in-place-small is-plain">{{ $document->ocr_text }}</p>@endif
            </section>
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

@can('documents.edit')
    <div class="dialog-backdrop" data-dialog="move-document-{{ $document->id }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="move-document-{{ $document->id }}-title">
            <h2 id="move-document-{{ $document->id }}-title">{{ __('documents.move') }}</h2>
            <form method="post" action="{{ route('documents.move', $document->id) }}">
                @csrf
                <label for="move-folder-{{ $document->id }}" class="visually-hidden">{{ __('folders.folder') }}</label>
                <select id="move-folder-{{ $document->id }}" name="folder" required>
                    @foreach ($folders as $row)
                        <option value="{{ $row['folder']->id }}" @selected($document->folder_id === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                    @endforeach
                </select>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                    <button type="submit">{{ __('documents.move_submit') }}</button>
                </div>
            </form>
        </div>
    </div>
@endcan

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
