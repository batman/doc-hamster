{{-- Preview branches found by "Vorschau-Branches abrufen" (points 8, 9, A20); null = not asked yet. --}}
<div class="pick-list" role="radiogroup" aria-label="{{ __('updates.preview_choose') }}" aria-live="polite">
    @if ($failed ?? false)
        <p class="list-empty list-error">{{ __('updates.unreachable') }}</p>
    @elseif (empty($branches))
        <p class="list-empty">{{ __('updates.no_entries') }}</p>
    @else
        @foreach ($branches as $branch)
            <label class="pick-row">
                <input type="radio" class="visually-hidden" name="preview_pr" value="{{ $branch['number'] }}" required>
                <x-icon name="git-pull-request" />
                <span><strong>#{{ $branch['number'] }} · {{ $branch['branch'] }}</strong><small>{{ $branch['title'] }}</small></span>
                <span class="pick-mark"><x-icon name="check" /></span>
            </label>
        @endforeach
    @endif
</div>
