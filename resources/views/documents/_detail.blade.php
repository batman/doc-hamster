{{-- The receipt as a window right of the list (window system, A76). Its dialogs live inside the window, so
     they come along when it is loaded in the background; their names carry the receipt's id.
     The document is only shown, never opened by a click: downloads go through the button. --}}
@php
    $file = $document->files->first();
    $close = route('documents.index', request()->query());
    $when = fn ($date) => $date?->format('d.m.Y H:i') ?? __('documents.never');
    $booking = $document->matches->map(fn ($match) => $match->transaction->booking_date->format('d.m.Y').' · '.$match->transaction->formattedAmount())->implode(', ');
    $shareUrl = session('share_url');
    $tagSummary = $document->tags->pluck('name')->implode(', ');
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
@php
    $canEdit = Gate::allows('documents.edit');
    $canTrash = Gate::allows('documents.trash');
@endphp
{{-- Points 185.2, A364: the name is edited in the window title (Fenstertitel umbenennen) – no name field in the body. --}}
<x-window :title="$document->title" icon="file-text" kind="detail" :url="route('documents.show', [$document->id, ...request()->query()])" fit="medium" content="detail" :close="$close" class="win-detail" :drag-doc="$document->id" :drag-file="$document->dragFile()"
    :rename-url="$canEdit ? route('documents.field', $document->id) : null" rename-field="value" :rename-data="['field' => 'title']">
    {{-- Only "Herunterladen" stays a button of its own; everything else is in the ⋯ menu (point 26). --}}
    @if ($file)
        <x-slot:actions>
            <a class="icon-button" href="{{ route('documents.download', $file->id) }}" aria-label="{{ __('documents.download') }}" data-tooltip="{{ __('documents.download') }}"><x-icon name="download" /></a>
        </x-slot:actions>
    @endif
    {{-- Everything else is in the module's ⋯ menu (point 26). --}}
    <x-slot:menu>
        @if ($isText)
            {{-- Text documents open in their own editor window (point 21); without documents.edit it only reads. --}}
            <a class="menu-item" href="{{ route('documents.text', $document->id) }}" data-window><x-icon name="pencil" /><span>{{ $canEdit ? __('documents.edit') : __('documents.text.open') }}</span></a>
        @endif
        @if ($file)
            {{-- The device's share menu with the file itself (A2); where there is none, our dialog with mail and link (A3). --}}
            <button type="button" class="menu-item" data-share="{{ route('documents.download', $file->id) }}" data-share-name="{{ $document->title }}" data-share-type="{{ $file->mime }}" data-dialog-open="share-document-{{ $document->id }}"><x-icon name="send" /><span>{{ __('documents.send') }}</span></button>
        @endif
        @if ($canEdit)
            {{-- Points 185.3, A363: moving is drag and drop; this entry is the touch/keyboard way (dialog with the Ordner-Menü). --}}
            <button type="button" class="menu-item" data-dialog-open="move-document-{{ $document->id }}"><x-icon name="arrows-move" /><span>{{ __('documents.move_menu') }}</span></button>
        @endif
        @if ($canEdit && ! $isText && app(\App\Infrastructure\Environment\LocalOcr::class)->serverReads($file))
            {{-- Point 186.3: the server reads this document again (also with server recognition off); modules/ocr-server.js. --}}
            <form method="post" action="{{ route('ocr.redo-server', $document->id) }}" data-ocr-server-redo>
                @csrf
                <button type="submit" class="menu-item"><x-icon name="server" /><span>{{ __('ocr.server.redo') }}</span></button>
            </form>
        @endif
        @if ($canTrash)
            <hr class="menu-separator">
            <button type="button" class="menu-item menu-danger" data-dialog-open="delete-document-{{ $document->id }}"><x-icon name="trash" /><span>{{ __('documents.delete') }}</span></button>
        @endif
    </x-slot:menu>

    @php
        // Points 168.1/168.2: the viewer (picture or PDF + zoom bar) sits in a pane whose height the edge below
        // changes (remembered per kind 'detail'); the fullscreen viewer is cloned from the template at the end.
        $isPdf = $file?->mime === 'application/pdf';
        $viewer = ! $isText && ($file?->isImage() || $file?->thumb_path || $isPdf);
        $viewerSrc = $file?->isImage() ? route('documents.file', $file->id) : ($file?->thumb_path ? route('documents.thumb', $file->id) : null);
    @endphp
    @if ($viewer)<div class="pane" data-pane="detail" style="--pane-size: var(--pane-detail)">@endif
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
    @if ($viewer)
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
            <button type="button" class="icon-button button-small" data-pane-reset="detail" aria-label="{{ __('documents.viewer.height_reset') }}" data-tooltip="{{ __('documents.viewer.height_reset') }}"><x-icon name="restore" /></button>
            <button type="button" class="icon-button button-small" data-doc-fullscreen="{{ $document->id }}" aria-label="{{ __('documents.viewer.fullscreen') }}" data-tooltip="{{ __('documents.viewer.fullscreen') }}"><x-icon name="maximize" /></button>
        </div>
        </div>
        <div class="pane-edge" data-pane-edge="detail" role="separator" aria-orientation="horizontal" tabindex="0" aria-label="{{ __('documents.viewer.height') }}" data-tooltip="{{ __('documents.viewer.height') }}"></div>
        {{-- Fullscreen viewer (point 168.2, modules/doc-fullscreen.js): a framework dialog over the whole app; the same
             frame + zoom bar pair as above, so modules/document-viewer.js drives it (zoom, pan, pages, word overlay). --}}
        <template data-doc-fullscreen-tpl>
            <div class="dialog-backdrop doc-fullscreen" hidden>
                <div class="dialog doc-fullscreen-box" role="dialog" aria-modal="true" aria-label="{{ $document->title }}">
                    <button type="button" class="icon-button button-small dialog-x" data-dialog-close aria-label="{{ __('documents.close') }}" data-tooltip="{{ __('documents.close') }}"><x-icon name="x" /></button>
                    <span class="thumb-frame detail-doc doc-fullscreen-frame" tabindex="0" @if ($isPdf) data-pdf="{{ route('documents.file', $file->id) }}" @endif>
                        @if ($viewerSrc)
                            <img class="thumb" src="{{ $viewerSrc }}" alt="" draggable="false">
                        @else
                            <x-icon :name="$file->typeIcon()" />
                        @endif
                    </span>
                    <div class="doc-zoom doc-fullscreen-bar" data-doc-zoom data-label-zoom="{{ __('documents.zoom_in_area') }}" data-label-copy="{{ __('documents.copy_area') }}" data-label-copied="{{ __('documents.copied') }}">
                        <div class="button-group" role="group" data-fs-docs hidden>
                            <button type="button" class="icon-button button-small" data-fs-doc-step="-1" aria-label="{{ __('documents.viewer.doc_prev') }}" data-tooltip="{{ __('documents.viewer.doc_prev') }} (←)"><x-icon name="chevron-left" /></button>
                            <button type="button" class="icon-button button-small" data-fs-doc-step="1" aria-label="{{ __('documents.viewer.doc_next') }}" data-tooltip="{{ __('documents.viewer.doc_next') }} (→)"><x-icon name="chevron-right" /></button>
                        </div>
                        <span class="doc-fullscreen-title">{{ $document->title }}</span>
                        <div class="button-group" role="group" aria-label="{{ __('documents.zoom') }}">
                            <button type="button" class="icon-button button-small" data-fs-zoom="out" aria-label="{{ __('documents.viewer.zoom_out') }}" data-tooltip="{{ __('documents.viewer.zoom_out') }}"><x-icon name="zoom-out" /></button>
                            <button type="button" class="icon-button button-small" data-fs-zoom="in" aria-label="{{ __('documents.viewer.zoom_in') }}" data-tooltip="{{ __('documents.viewer.zoom_in') }}"><x-icon name="zoom-in" /></button>
                        </div>
                        <output class="doc-fullscreen-value" data-zoom-value>100 %</output>
                        <div class="button-group" role="group">
                            <button type="button" class="icon-button button-small" data-fs-zoom="width" aria-label="{{ __('documents.viewer.fit_width') }}" data-tooltip="{{ __('documents.viewer.fit_width') }}"><x-icon name="arrow-autofit-width" /></button>
                            <button type="button" class="icon-button button-small" data-fs-zoom="page" aria-label="{{ __('documents.viewer.fit_page') }}" data-tooltip="{{ __('documents.viewer.fit_page') }}"><x-icon name="arrow-autofit-height" /></button>
                            <button type="button" class="icon-button button-small" data-fs-zoom="actual" aria-label="{{ __('documents.viewer.actual') }}" data-tooltip="{{ __('documents.viewer.actual') }}"><x-icon name="zoom-reset" /></button>
                        </div>
                        @if ($isPdf)
                            <div class="doc-pages" data-doc-pages data-label="{{ __('documents.page_of') }}" hidden>
                                <div class="button-group" role="group" aria-label="{{ __('documents.pages') }}">
                                    <button type="button" class="icon-button button-small" data-page-step="-1" aria-label="{{ __('documents.page_prev') }}" data-tooltip="{{ __('documents.page_prev') }}"><x-icon name="chevron-left" /></button>
                                    <button type="button" class="icon-button button-small" data-page-step="1" aria-label="{{ __('documents.page_next') }}" data-tooltip="{{ __('documents.page_next') }}"><x-icon name="chevron-right" /></button>
                                </div>
                                <output data-page-label></output>
                            </div>
                        @endif
                        <a class="icon-button button-small" href="{{ route('documents.download', $file->id) }}" aria-label="{{ __('documents.download') }}" data-tooltip="{{ __('documents.download') }}"><x-icon name="download" /></a>
                    </div>
                </div>
            </div>
        </template>
    @endif

    {{-- Eigenschaften (points 122.4, A252): the values of this document, editable in place. --}}
    @include('documents._properties')
    @include('bank._standing')

    {{-- GiroCode (point 120): payee, IBAN, amount and the QR code for the banking app. --}}
    @include('documents._girocode')

    @if ($canEdit)
        @if ($tags->isNotEmpty())
            {{-- Point 170.1: no save button – every tag tick saves at once (FRAMEWORK.md "Properties module"). Points 185.2/185.3:
                 the name is renamed in the window title, the folder changes by drag and drop or "Verschieben …" in the ⋯ menu.
                 Without JavaScript the form posts with its noscript button. --}}
            <form method="post" action="{{ route('documents.update', $document->id) }}" data-autosave data-doc-detail="{{ $document->id }}">
                @csrf
                {{-- Foldable card (point 132, A279: open at first); folded the head names the chosen tags. --}}
                <x-fold-card fold="detail.tags" :title="__('documents.tags')" icon="tags" :summary="$tagSummary">
                    <div class="tags">
                        @foreach ($tags as $tag)
                            <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked($document->tags->contains('id', $tag->id))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
                        @endforeach
                    </div>
                </x-fold-card>
                <noscript><button type="submit">{{ __('documents.save') }}</button></noscript>
            </form>
        @endif
    @else
        <x-fold-card fold="detail.tags" :title="__('documents.tags')" icon="tags" :summary="$tagSummary">
            @include('documents._tags')
        </x-fold-card>
    @endif

    {{-- Point 185.1: Notiz and Erkannter Text are foldable cards like the others (fold state remembered). The note is edited
         in place and saves on its own (points 27/28); folded, the head shows its beginning. --}}
    @if ($canEdit || $document->note)
        <x-fold-card fold="detail.note" :title="__('documents.note')" icon="note" :summary="\Illuminate\Support\Str::limit((string) $document->note, 60)">
            @if ($canEdit)
                <x-edit-in-place name="note" :value="$document->note" :action="route('documents.field', $document->id)" multiline maxlength="5000"
                    :placeholder="__('documents.note_placeholder')" :label="__('documents.note')" />
            @else
                <p>{{ $document->note }}</p>
            @endif
        </x-fold-card>
    @endif

    @if ($canEdit || $document->ocr_text || ! $isText)
        {{-- Folded, the head names the recognition state; open, its tools stand in one row above the text. --}}
        <x-fold-card fold="detail.ocr" :title="__('documents.ocr_text')" icon="text-scan-2" :summary="$isText ? '' : __('ocr.state.'.$ocrState)" data-ocr-card>
            <div class="ocr-head">
                @unless ($isText)
                    <span class="pill">{{ __('ocr.state.'.$ocrState) }}</span>
                    @if ($canEdit && $ocrState !== 'running')
                        <form method="post" action="{{ route('ocr.redo', $document->id) }}">
                            @csrf
                            {{-- A345: with extra OCR languages installed, one document can be read with a language of its own. --}}
                            @php($ocrLanguages = app(\App\Infrastructure\Environment\LocalOcr::class)->installedLanguages(false))
                            @if (count($ocrLanguages) > count(\App\Infrastructure\Environment\LocalOcr::CORE_LANGUAGES))
                                <select name="langs" class="input-small" aria-label="{{ __('extensions.redo_languages') }}">
                                    <option value="">{{ __('extensions.redo_all') }}</option>
                                    @foreach ($ocrLanguages as $code)
                                        <option value="{{ $code }}" @selected($document->ocr_langs === $code)>{{ __('extensions.languages.'.$code) }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <button type="submit" class="button-quiet button-small">{{ __('ocr.redo') }}</button>
                        </form>
                    @endif
                @endunless
                @include('documents._translate')
                @include('documents._ocr_copy')
            </div>
            @include('documents._ocr_read')
            {{-- Point 134: the text is no longer edited as a block; words are corrected one by one in the word popup. --}}
            @if ($document->ocr_text)
                <p class="edit-in-place-small is-plain">{{ $document->ocr_text }}</p>
            @elseif ($canEdit)
                <p class="muted">{{ __('documents.ocr_placeholder') }}</p>
            @endif
            @include('documents._translations')
        </x-fold-card>
    @endif

    {{-- Foldable card (point 132, A279: folded at first). --}}
    <x-fold-card fold="detail.history" :title="__('documents.history')" icon="clock" folded
        :summary="__('documents.facts.uploaded').' '.$when($document->created_at)">
        <dl class="detail-facts">
            <dt>{{ __('documents.facts.uploaded') }}</dt><dd>{{ $when($document->created_at) }}</dd>
            <dt>{{ __('documents.facts.created') }}</dt><dd>{{ $document->doc_date?->format('d.m.Y') ?? '–' }}</dd>
            <dt>{{ __('documents.facts.opened') }}</dt><dd>{{ $when($document->last_opened_at) }}</dd>
            <dt>{{ __('documents.facts.downloaded') }}</dt><dd>{{ $when($document->last_downloaded_at) }}</dd>
            @if ($document->ocr_read_at)
                {{-- Point 186.4: when and by whom the text was read. --}}
                <dt>{{ __('documents.facts.recognized') }}</dt><dd>{{ $when($document->ocr_read_at) }}@if ($document->ocr_engine) ({{ __('documents.facts.engines.'.$document->ocr_engine) }})@endif</dd>
            @endif
            <dt>{{ __('documents.facts.type') }}</dt><dd>{{ $file?->mime ?? '–' }}</dd>
            <dt>{{ __('documents.facts.booking') }}</dt><dd>{{ $booking !== '' ? $booking : '–' }}</dd>
        </dl>
    </x-fold-card>

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
    {{-- "Verschieben …" (points 185.3, A363): the Ordner-Menü (recent folders, the tree with the document's folder path open,
         A368); a pick moves at once with a toast and
         "Rückgängig" (documents-forms.js). Without JavaScript the select below posts to the move route. --}}
    @php($recent = \App\Domain\Documents\Inbox::recentFolders($document->org_id, (string) app(\App\Domain\Org\CurrentOrg::class)->membership()->user_id))
    <div class="dialog-backdrop" data-dialog="move-document-{{ $document->id }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="move-document-{{ $document->id }}-title">
            <h2 id="move-document-{{ $document->id }}-title">{{ __('documents.move') }}</h2>
            <div class="folder-menu" data-doc-move-menu="{{ $document->id }}" data-expand="{{ __('inbox.expand') }}" data-collapse="{{ __('inbox.collapse') }}">
                @include('inbox._folder-menu-items', ['current' => $document->folder_id])
            </div>
            <noscript>
                <form method="post" action="{{ route('documents.move', $document->id) }}">
                    @csrf
                    <select name="folder" required aria-label="{{ __('folders.folder') }}">
                        @foreach ($folders as $row)
                            <option value="{{ $row['folder']->id }}" @selected($document->folder_id === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit">{{ __('documents.move_submit') }}</button>
                </form>
            </noscript>
            <div class="dialog-actions">
                <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
            </div>
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
