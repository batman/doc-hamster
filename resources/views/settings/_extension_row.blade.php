{{-- One data pack on Einstellungen → Erweiterungen (point 173). 172: a bundle has no files of its own, so no on/off. --}}
@php($ext = $row['ext'])
@php($name = \App\Http\Controllers\ExtensionController::name($ext))
<li class="pick-row is-static">
    <x-icon :name="$row['installed'] ? 'circle-check' : 'language'" />
    <span>
        <strong>{{ $name }}</strong>
        <small>{{ \App\Http\Controllers\ExtensionController::text($ext) }} · {{ \App\Http\Controllers\ExtensionController::size($ext) }} · {{ __('extensions.version', ['version' => $row['version'] ?? $ext->version]) }}</small>
    </span>
    @if ($row['installed'])
        <span class="pill pill-ok">{{ __('extensions.installed_pill') }}</span>
        @unless ($row['enabled'] || $ext->files === [])
            <span class="pill">{{ __('extensions.off_pill') }}</span>
        @endunless
    @endif
    <span class="row-actions">
        @if (! $row['installed'] || $row['updatable'])
            <form method="post" action="{{ route('extensions.install', $ext->key) }}">
                @csrf
                <button type="submit" class="button-small">
                    <x-icon :name="$row['installed'] ? 'cloud-download' : 'download'" />{{ __($row['installed'] ? 'extensions.update' : 'extensions.install') }}
                </button>
            </form>
        @endif
        @if ($row['installed'] && $ext->files !== [])
            <form method="post" action="{{ route('extensions.toggle', $ext->key) }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $row['enabled'] ? '0' : '1' }}">
                <button type="submit" class="control-button" aria-label="{{ __($row['enabled'] ? 'extensions.disable' : 'extensions.enable') }}: {{ $name }}" data-tooltip="{{ __($row['enabled'] ? 'extensions.disable' : 'extensions.enable') }}">
                    <x-icon :name="$row['enabled'] ? 'player-pause' : 'player-play'" />
                </button>
            </form>
        @endif
        @if ($row['installed'])
            <button type="button" class="button-small button-quiet" data-dialog-open="remove-extension-{{ $ext->key }}"><x-icon name="trash" />{{ __('extensions.remove') }}</button>
        @endif
    </span>
</li>
