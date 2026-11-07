{{-- Releases found by "Auf neue Version prüfen" (Go 2026-11-07, points 8, 9, A35, A36); null = not asked yet. --}}
<ul class="pick-list" aria-live="polite">
    @if ($failed ?? false)
        <li class="list-empty list-error">{{ __('updates.unreachable') }}</li>
    @elseif (empty($releases))
        <li class="list-empty">{{ __('updates.no_entries') }}</li>
    @else
        @foreach ($releases as $release)
            <li class="pick-row is-static">
                <x-icon name="cloud-download" />
                <span><strong>{{ __('updates.version_row', ['version' => $release['version']]) }}</strong><small>{{ $release['main'] ? __('updates.main_hint') : __('updates.keeps_data') }}</small></span>
                @if ($release['version'] === (string) config('dochamster.version'))
                    <span class="tag-outline">{{ __('updates.installed_label') }}</span>
                @elseif ($supported ?? false)
                <button type="button" class="button-small" data-dialog-open="update-{{ $release['main'] ? 'main' : 'release' }}" data-dialog-title="{{ __('updates.install_version', ['version' => $release['version']]) }}">{{ __('updates.install_short') }}</button>
                @endif
            </li>
        @endforeach
    @endif
</ul>
