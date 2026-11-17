{{-- Software → Module (D-041, points 10–19): the installed modules (version, rights, on/off, error, update, remove),
     the catalogue's announced modules ("kommt später"), and – for the owner – "Modul hinzufügen": a GitHub address
     or the partner list, looked up only on a click; what was found can be installed after the foreign-code notice. --}}
@php
    $installed = app(\App\Modules\InstalledModules::class)->all();
    $found = session('modules.found', []);
    $manage = Gate::allows('modules.manage');
    $rightNames = static fn (array $rights): string => implode(', ', array_map(static fn (string $r): string => __('modules.rights.'.$r), $rights));
@endphp
<section class="card" id="modules">
    <h2 class="card-head"><x-icon name="puzzle" />{{ __('extensions.kinds.module') }}</h2>
    <p class="card-text">{{ __('extensions.kind_texts.module') }}</p>
    <ul class="pick-list">
        @foreach ($installed as $key => $entry)
            @php
                $m = $entry['manifest'];
                $newer = ($entry['latest']['version'] ?? null) !== null && version_compare($entry['latest']['version'], (string) $m['version'], '>');
            @endphp
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon name="puzzle" /></span>
                <span>
                    <strong>{{ $m['name'] }}</strong>
                    <small>{{ __('modules.by', ['provider' => $m['provider'], 'version' => $m['version']]) }} · {{ $entry['rights'] === [] ? __('modules.granted_none') : __('modules.granted', ['rights' => $rightNames($entry['rights'])]) }}</small>
                    @if ($entry['error'])<small class="error">{{ $entry['error'] }}</small>@endif
                </span>
                @if ($newer)<span class="pill pill-new">{{ __('modules.available') }}</span>@endif
                <span @class(['pill', 'pill-ok' => $entry['enabled'], 'pill-warning' => ! $entry['enabled'] && $entry['error']])>{{ __($entry['enabled'] ? 'modules.pill_on' : ($entry['error'] ? 'modules.pill_error' : 'modules.pill_off')) }}</span>
                @if ($manage)
                    <span class="row-actions">
                        @if ($newer)
                            <button type="button" class="control-button" data-dialog-open="module-update-{{ $key }}" aria-label="{{ __('modules.update') }}" data-tooltip="{{ __('modules.update') }}"><x-icon name="refresh" /></button>
                        @endif
                        <form method="post" action="{{ route('modules.toggle', $key) }}">
                            @csrf
                            <button type="submit" class="control-button" aria-label="{{ __($entry['enabled'] ? 'modules.off' : 'modules.on') }}" data-tooltip="{{ __($entry['enabled'] ? 'modules.off' : 'modules.on') }}"><x-icon name="power" /></button>
                        </form>
                        <button type="button" class="control-button control-danger" data-dialog-open="module-remove-{{ $key }}" aria-label="{{ __('modules.remove') }}" data-tooltip="{{ __('modules.remove') }}"><x-icon name="trash" /></button>
                    </span>
                @endif
            </li>
        @endforeach
        @foreach ($modules as $row)
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon name="puzzle" /></span>
                <span>
                    <strong>{{ \App\Http\Controllers\ExtensionController::name($row['ext']) }}</strong>
                    <small>{{ \App\Http\Controllers\ExtensionController::text($row['ext']) }} · {{ __('extensions.later') }}</small>
                </span>
                <span class="pill">{{ __('extensions.later_pill') }}</span>
            </li>
        @endforeach
        @if ($installed === [] && $modules === [])
            <x-empty tag="li" icon="puzzle" :text="__('extensions.no_modules')" />
        @endif
    </ul>

</section>

    @if ($manage)
    <section class="card">
        <h2 class="card-head"><x-icon name="download" />{{ __('modules.add_title') }}</h2>
        <p class="card-text">{{ __('modules.add_text') }}</p>
        <form method="post" action="{{ route('modules.find') }}">
            @csrf
            <div class="field-row"><input type="url" name="address" inputmode="url" placeholder="https://github.com/…" aria-label="{{ __('modules.address') }}" required><button type="submit"><x-icon name="search" />{{ __('modules.find') }}</button></div>
        </form>
        <form method="post" action="{{ route('modules.partners') }}">
            @csrf
            <p class="card-note">{{ __('modules.partners_text') }}</p>
            <button type="submit" class="button-small button-quiet"><x-icon name="users" />{{ __('modules.partners') }}</button>
        </form>
        @if ($found !== [])
            <p class="card-text"><strong>{{ __('modules.found') }}</strong></p>
            <ul class="pick-list">
                @foreach ($found as $i => $hit)
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon name="puzzle" /></span>
                        <span>
                            <strong>{{ $hit['manifest']['name'] }}</strong>
                            <small>{{ $hit['manifest']['description'] }}</small>
                            <small>{{ __('modules.by', ['provider' => $hit['manifest']['provider'], 'version' => $hit['manifest']['version']]) }} · {{ $hit['source'] }}</small>
                        </span>
                        @if (isset($installed[$hit['manifest']['key']]))
                            <span class="pill pill-ok">{{ __('updates.installed_badge') }}</span>
                        @else
                            <button type="button" class="button-small" data-dialog-open="module-install-{{ $i }}"><x-icon name="download" />{{ __('modules.install') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endif

@if ($manage)
    @include('modules._dialogs')
@endif
