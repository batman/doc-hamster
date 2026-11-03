{{-- The receipt next to the list (design point 17); below 80rem the CSS shows it as a dialog over the list. --}}
@php
    $file = $document->files->first();
    $close = route('documents.index', request()->query());
    $when = fn ($date) => $date?->format('d.m.Y H:i') ?? __('documents.never');
    $booking = $document->matches->map(fn ($match) => $match->transaction->booking_date->format('d.m.Y').' · '.$match->transaction->formattedAmount())->implode(', ');
@endphp
<a class="split-backdrop" href="{{ $close }}" aria-label="{{ __('documents.close') }}"></a>
<aside class="split-detail" aria-label="{{ $document->title }}">
    <div class="detail-head">
        <h2>{{ $document->title }}</h2>
        <a class="icon-button" href="{{ $close }}" aria-label="{{ __('documents.close') }}" data-tooltip="{{ __('documents.close') }}"><x-icon name="x" /></a>
    </div>

    <div class="detail-actions">
        @if ($file)
            <a class="icon-button" href="{{ route('documents.download', $file->id) }}" aria-label="{{ __('documents.download') }}" data-tooltip="{{ __('documents.download') }}"><x-icon name="download" /></a>
        @endif
        @can('documents.edit')
            <a class="icon-button" href="#title" aria-label="{{ __('documents.edit') }}" data-tooltip="{{ __('documents.edit') }}"><x-icon name="pencil" /></a>
        @endcan
        <button type="button" class="icon-button" aria-label="{{ __('documents.send_later') }}" data-tooltip="{{ __('documents.send_later') }}" disabled><x-icon name="send" /></button>
        @can('documents.trash')
            <button type="button" class="icon-button" aria-label="{{ __('documents.delete') }}" data-tooltip="{{ __('documents.delete') }}" data-dialog-open="delete-document"><x-icon name="trash" /></button>
        @endcan
    </div>

    @if ($file)
        <a href="{{ route('documents.file', $file->id) }}" target="_blank" rel="noopener" aria-label="{{ __('documents.open_file') }}">
            @if ($file->isImage())
                <span class="thumb-frame detail-doc"><img class="thumb" src="{{ route('documents.file', $file->id) }}" alt=""></span>
            @else
                @include('documents._thumb', ['frameClass' => 'detail-doc'])
            @endif
        </a>
    @endif

    @can('documents.edit')
        <form method="post" action="{{ route('documents.update', $document->id) }}">
            @csrf
            <section class="detail-section">
                <h3><label for="title">{{ __('documents.name') }}</label></h3>
                <input id="title" name="title" value="{{ old('title', $document->title) }}" maxlength="250" required>
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
                <h3><label for="note">{{ __('documents.note') }}</label></h3>
                <textarea id="note" name="note" placeholder="{{ __('documents.note_placeholder') }}">{{ old('note', $document->note) }}</textarea>
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
</aside>

@can('documents.trash')
    <div class="dialog-backdrop" data-dialog="delete-document" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-document-title">
            <h2 id="delete-document-title">{{ __('documents.delete_title') }}</h2>
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
