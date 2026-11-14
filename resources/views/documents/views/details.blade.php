{{-- Point 113: no own scroll box – the table always fits the window; A222: the Tags column only when a row has tags;
     A220: narrow windows drop Tags first, then the time (container queries in css/modules/documents.css).
     A244: the property columns this person chose (⋯ menu → Spalten); a click on a head sorts on the server. --}}
@php
    $withTags = $documents->contains(fn ($d) => $d->tags->isNotEmpty());
    $propertyFormat = app(\App\Domain\Properties\PropertyValues::class);
    // The head of a sortable column: a link that sorts by it (again: the other way round) and shows the direction.
    $sortHead = function (string $key, string $label) use ($sort, $dir) {
        $current = $sort === $key;
        $next = $current ? ($dir === 'asc' ? 'desc' : 'asc') : ($key === 'date' ? 'desc' : 'asc');

        return ['href' => request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => null]), 'current' => $current, 'label' => $label];
    };
@endphp
<div class="view-details">
    <table>
        <thead>
            <tr>
                @foreach ([['name', __('documents.name'), ''], ['date', __('documents.date'), 'col-date']] as [$key, $label, $class])
                    @if ($key === 'date' && $searching)
                        <th scope="col" class="col-folder">{{ __('documents.folder') }}</th>
                    @endif
                    @php $head = $sortHead($key, $label); @endphp
                    <th scope="col" @if ($class) class="{{ $class }}" @endif @if ($head['current']) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                        <a href="{{ $head['href'] }}" data-doc-sort aria-label="{{ __('properties.sort', ['name' => $label]) }}">{{ $label }}@if ($head['current'])<x-icon :name="$dir === 'asc' ? 'arrow-up' : 'arrow-down'" />@endif</a>
                    </th>
                @endforeach
                @foreach ($columns as $column)
                    @php $head = $sortHead('prop:'.$column->id, $column->name); @endphp
                    <th scope="col" class="col-prop @if (in_array($column->type, ['amount', 'number'], true)) col-prop-num @endif" @if ($head['current']) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                        <a href="{{ $head['href'] }}" data-doc-sort aria-label="{{ __('properties.sort', ['name' => $column->name]) }}">{{ $column->name }}@if ($head['current'])<x-icon :name="$dir === 'asc' ? 'arrow-up' : 'arrow-down'" />@endif</a>
                    </th>
                @endforeach
                @if ($withTags)
                    <th scope="col" class="col-tags">{{ __('documents.tags') }}</th>
                @endif
                <th scope="col" class="col-actions"><span class="visually-hidden">{{ __('folders.properties') }}</span></th>
            </tr>
        </thead>
        <tbody>
            {{-- data-doc: a row that can be picked (Ctrl/Shift, A132) and dragged onto a folder row (point 75). --}}
            @foreach ($documents as $document)
                <tr data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" data-doc="{{ $document->id }}" data-doc-file="{{ $document->dragFile() }}" data-folder="{{ $document->folder_id }}" @if ($open?->id === $document->id) class="is-open" @endif>
                    <td><a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}" data-window>@include('documents._type-icon')<span data-doc-title>{{ $document->title }}</span></a></td>
                    @if ($searching)
                        <td>@include('documents._folder-link')</td>
                    @endif
                    <td class="col-date">{{ $document->created_at->format('d.m.Y') }}<span class="col-time"> {{ $document->created_at->format('H:i') }}</span></td>
                    @foreach ($columns as $column)
                        @php $cell = $document->properties->firstWhere('property_id', $column->id)?->setRelation('property', $column); @endphp
                        <td class="col-prop @if (in_array($column->type, ['amount', 'number'], true)) col-prop-num @endif">{{ $cell ? $propertyFormat->display($cell) : '' }}</td>
                    @endforeach
                    @if ($withTags)
                        <td class="col-tags">@include('documents._tags')</td>
                    @endif
                    <td class="row-actions-cell">@include('documents._row-actions')</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
