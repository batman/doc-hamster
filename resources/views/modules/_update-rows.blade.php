{{-- F16: the installed modules in the version list of the Software page – after "Update" with their newest version;
     "Aktualisieren" opens the module's update dialog (modules/_dialogs). --}}
@foreach (app(\App\Modules\InstalledModules::class)->all() as $key => $entry)
    @php
        $m = $entry['manifest'];
        $latest = $entry['latest']['version'] ?? null;
        $newer = $latest !== null && version_compare($latest, (string) $m['version'], '>');
    @endphp
    <li class="pick-row is-static">
        <span class="row-icon"><x-icon name="puzzle" /></span>
        <span><strong>{{ $m['name'] }}</strong><small>{{ $newer ? __('modules.version_to', ['from' => $m['version'], 'to' => $latest]) : __('modules.version', ['version' => $m['version']]) }}</small></span>
        <span class="pill pill-ok">{{ __('updates.installed_badge') }}</span>
        @if ($newer)
            <span class="pill pill-new">{{ __('updates.available_badge') }}</span>
            @can('modules.manage')
                <button type="button" class="button-small" data-dialog-open="module-update-{{ $key }}">{{ __('updates.update_short') }}</button>
            @endcan
        @endif
    </li>
@endforeach
