{{-- Preview branches found by "Vorschau-Branches abrufen" (points 8, 9, 15, A53-A55); null = not asked yet. --}}
@php([$installedPr, $installedSha] = preg_match('/^preview-(\d+)-([0-9a-f]+)$/', (string) config('dochamster.version'), $m) === 1 ? [(int) $m[1], $m[2]] : [null, ''])
<ul class="pick-list" aria-live="polite">
    @if ($failed ?? false)
        <li class="list-empty list-error">{{ __('updates.unreachable') }}</li>
    @elseif (empty($branches))
        <li class="list-empty">{{ __('updates.no_entries') }}</li>
    @else
        @foreach ($branches as $branch)
            <li class="pick-row is-static">
                <x-icon name="git-pull-request" />
                <span><strong>#{{ $branch['number'] }} · {{ $branch['branch'] }}</strong><small>{{ $branch['title'] }}</small></span>
                @php($mine = $installedPr === (int) $branch['number'])
                @if ($mine && str_starts_with($branch['sha'], $installedSha))
                    <span class="tag-outline">{{ __('updates.installed_label') }}</span>
                @elseif ($supported ?? false)
                    {{-- A newer state of the installed branch is offered as "Aktualisieren" (A95). --}}
                    <button type="button" class="button-small" data-dialog-open="update-preview" data-preview-pr="{{ $branch['number'] }}" data-dialog-title="{{ __('updates.preview_install_number', ['number' => $branch['number']]) }}">{{ __($mine ? 'updates.update_short' : 'updates.install_short') }}</button>
                @endif
            </li>
        @endforeach
    @endif
</ul>
