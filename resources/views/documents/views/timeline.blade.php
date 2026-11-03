{{-- Newest month first, by upload date until receipts have a date of their own. --}}
<div class="timeline">
    @foreach ($documents->groupBy(fn ($document) => $document->created_at->format('Y-m')) as $group)
        <section data-group>
            <h2>{{ $group->first()->created_at->locale(app()->getLocale())->translatedFormat('F Y') }}</h2>
            <ul>
                @foreach ($group as $document)
                    <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" @if ($open?->id === $document->id) class="is-open" @endif>
                        <span class="when muted">{{ $document->created_at->format('d.m. H:i') }}</span>
                        @include('documents._thumb')
                        <a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}">{{ $document->title }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
