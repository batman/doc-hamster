{{-- Posteingang (A349): the newest five with their source. --}}
@if ($documents->isEmpty())
    <x-empty icon="inbox" :text="__('dashboard.inbox_empty')" />
@else
    <ul class="pick-list dash-list">
        @foreach ($documents as $document)
            @php $source = \App\Domain\Documents\Inbox::sourceOf($document); @endphp
            <li class="pick-row is-static">
                <span class="row-icon" data-tooltip="{{ __('inbox.sources.'.$source) }}"><x-icon :name="\App\Domain\Documents\Inbox::ICONS[$source]" /></span>
                <span><strong><a href="{{ route('documents.show', $document->id) }}" data-window>{{ $document->title }}</a></strong><small>{{ $document->created_at?->locale(app()->getLocale())->diffForHumans() }}</small></span>
            </li>
        @endforeach
    </ul>
@endif
<p class="dash-more"><a href="{{ route('inbox.index') }}" data-window>{{ __('dashboard.inbox_all') }}</a></p>
