{{-- What "Auf neue Version prüfen" found (points 8, 9, A35, A95): on Main a newer Main release, on a preview branch
     only a newer state of that branch; null = not asked yet. --}}
<ul class="pick-list" aria-live="polite">
    @if ($failed ?? false)
        <li class="list-empty list-error">{{ __('updates.unreachable') }}</li>
    @elseif ($branchGone ?? false)
        <li class="list-empty">{{ __('updates.preview_gone') }}</li>
    @elseif (! empty($branchUpdates))
        @foreach ($branchUpdates as $branch)
            <li class="pick-row is-static">
                <x-icon name="git-pull-request" />
                <span><strong>{{ __('updates.preview_newer', ['number' => $branch['number'], 'branch' => $branch['branch']]) }}</strong><small>{{ $branch['title'] }}</small></span>
                @if (($supported ?? false) && Gate::allows('system.update_preview'))
                    <button type="button" class="button-small" data-dialog-open="update-preview" data-preview-pr="{{ $branch['number'] }}" data-dialog-title="{{ __('updates.preview_install_number', ['number' => $branch['number']]) }}">{{ __('updates.update_short') }}</button>
                @endif
            </li>
        @endforeach
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
