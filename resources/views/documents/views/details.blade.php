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
                <tr data-item data-tags="{{ $document->tags->pluck('id')->implode(' ') }}">
                    <td><a href="{{ $document->fileUrl() }}">@include('documents._thumb'){{ $document->title }}</a></td>
                    <td>{{ $document->created_at->format('d.m.Y H:i') }}</td>
                    <td>
                        {{ $document->tags->pluck('name')->implode(', ') }}
                        @can('documents.edit')
                            @if ($tags->isNotEmpty())
                                <details>
                                    <summary>{{ __('tags.change') }}</summary>
                                    <form method="post" action="{{ route('documents.tags', $document->id) }}">
                                        @csrf
                                        @include('documents._tag-chips', ['selected' => $document->tags->pluck('id')->all()])
                                        <button type="submit">{{ __('tags.change') }}</button>
                                    </form>
                                </details>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
