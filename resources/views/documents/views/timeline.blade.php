{{-- Every document of every folder, newest month first, by the chosen date (points 100, 108): no folders, no dragging. --}}
<div class="timeline">
    @foreach ($documents->groupBy(fn ($document) => $document->dateBy($dates)->format('Y-m')) as $group)
        <section data-group>
            <h2>{{ $group->first()->dateBy($dates)->locale(app()->getLocale())->translatedFormat('F Y') }}</h2>
            <ul>
                @foreach ($group as $document)
                    @php($exact = $dates === 'upload' || $document->doc_date === null)
                    <li data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc-row tabindex="0" @if ($open?->id === $document->id) class="is-open" @endif>
                        <span class="when muted">{{ $document->dateBy($dates)->format($exact ? 'd.m. H:i' : 'd.m.') }}</span>
                        @include('documents._thumb')
                        <a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window tabindex="-1">{{ $document->title }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
