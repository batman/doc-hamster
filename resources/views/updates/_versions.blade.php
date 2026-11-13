{{-- All versions in one list (task 109): Main and every preview branch, the installed one marked. "Aktualisieren"
     stays in the installed branch, "Wechseln" is the only way to another one (both ask in a dialog first). --}}
@php
    $day = fn (string $date): string => $date === '' ? '' : \Illuminate\Support\Carbon::parse($date)->locale(app()->getLocale())->isoFormat('L');
@endphp
<ul class="pick-list" aria-live="polite">
    @if ($failed ?? false)
        <li class="list-empty list-error">{{ __('updates.unreachable') }}</li>
    @endif
    @foreach ($rows as $row)
        @php
            $preview = $row['kind'] === 'preview';
            if ($row['installed'] && $row['gone']) {
                $sub = __('updates.preview_gone');
            } elseif ($row['installed'] && $row['newer'] !== '') {
                $sub = __('updates.newer', ['from' => $row['version'], 'to' => $row['newer']]);
            } elseif ($row['installed'] || ! $preview) {
                $sub = $row['version'] === '' ? $row['title'] : ($row['date'] === '' ? $row['version'] : __('updates.version_date', ['version' => $row['version'], 'date' => $day($row['date'])]));
            } else {
                $sub = $row['date'] === '' ? $row['title'] : __('updates.version_date', ['version' => $row['title'], 'date' => $day($row['date'])]);
            }
        @endphp
        <li class="pick-row is-static @if ($row['installed']) is-marked @endif">
            <x-icon :name="$preview ? 'git-pull-request' : 'cloud-download'" />
            <span><strong>{{ $row['label'] }}</strong><small>{{ $sub }}</small></span>
            @if ($row['installed'])
                <span class="pill pill-ok">{{ __('updates.installed_badge') }}</span>
            @endif
            @if ($row['newer'] !== '')
                <span class="pill pill-new">{{ __('updates.available_badge') }}</span>
            @endif
            @if ($supported ?? false)
                @if ($row['installed'] && $row['newer'] !== '' && $row['updatable'])
                    <button type="button" class="button-small" data-dialog-open="{{ $preview ? 'update-preview' : 'update-main' }}" @if ($preview) data-preview-pr="{{ $row['number'] }}" @endif data-dialog-title="{{ __('updates.update_title', ['version' => $row['newer']]) }}">{{ __('updates.update_short') }}</button>
                @elseif (! $row['installed'] && $row['switchable'])
                    <button type="button" class="button-small button-quiet" data-dialog-open="{{ $preview ? 'switch-preview' : 'switch-main' }}" @if ($preview) data-preview-pr="{{ $row['number'] }}" @endif data-dialog-title="{{ $preview ? __('updates.switch_preview_title', ['number' => $row['number']]) : __('updates.switch_main_title') }}">{{ __('updates.switch') }}</button>
                @endif
            @endif
        </li>
    @endforeach
</ul>
