<div class="view-details">
    <table>
        <thead>
            <tr>
                <th scope="col">{{ __('documents.name') }}</th>
                <th scope="col">{{ __('documents.date') }}</th>
                <th scope="col">{{ __('documents.tags') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($documents as $document)
                <tr data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}" @if ($open?->id === $document->id) class="is-open" @endif>
                    <td><a href="{{ route('documents.show', [$document->id, ...request()->query()]) }}">@include('documents._type-icon'){{ $document->title }}</a></td>
                    <td>{{ $document->created_at->format('d.m.Y H:i') }}</td>
                    <td>@include('documents._tags')</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
