{{-- "Dokumente ohne Buchung" as a window: document rows (data-doc) that drag onto a booking row of an account window
     (A269); Ctrl/Shift picks several (A132), they are linked together. --}}
<x-window :title="__('bank.free_receipts')" icon="file-off" kind="bank-receipts" :url="route('bank.receipts', request()->only('q'))" :close="route('bank.index')"
          :search="__('bank.search_receipts')">
    <p class="card-note">{{ __('bank.receipts_hint') }}</p>
    <ul class="pick-list">
        @forelse ($receipts as $document)
            <li class="pick-row is-static" data-doc="{{ $document->id }}" data-doc-file="{{ $document->dragFile() }}" data-folder="{{ $document->folder_id }}">
                <span class="row-icon"><x-icon name="file-text" /></span>
                <span>
                    <strong><a href="{{ route('documents.show', $document->id) }}" data-window><span data-doc-title>{{ $document->title }}</span></a></strong>
                    <small>{{ $document->created_at->format('d.m.Y') }}</small>
                </span>
            </li>
        @empty
            <li class="list-empty">{{ __('bank.receipts_empty') }}</li>
        @endforelse
    </ul>
    @include('bank._pages', ['pages' => $receipts])
</x-window>
