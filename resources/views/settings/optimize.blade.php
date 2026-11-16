{{-- Einstellungen → Dokumentgröße optimieren (point 236): an optional module, off after install (F46). Progress like the
     Texterkennung (A410: optimiert, wartet, Platz gespart; modules/optimize.js refreshes it while files wait) and the
     settings as a Properties module – every choice saves at once, the threshold with its ✓. --}}
@extends('layouts.settings')

@section('title', __('optimize.title'))

@section('page')
    <p class="muted">{{ __('optimize.intro') }}</p>

    <section class="card" data-optimize-page="{{ route('optimize.status') }}" data-busy="{{ $settings['on'] && $counts['waiting'] > 0 ? '1' : '0' }}">
        <h2 class="card-head"><x-icon name="arrows-minimize" />{{ __('optimize.progress') }}</h2>
        <ul class="ocr-counts">
            <li><strong data-optimize-count="done">{{ $counts['done'] }}</strong><span>{{ __('optimize.counts.done') }}</span></li>
            <li><strong data-optimize-count="waiting">{{ $counts['waiting'] }}</strong><span>{{ __('optimize.counts.waiting') }}</span></li>
            <li><strong data-optimize-count="saved_text">{{ \App\Http\Controllers\OptimizeController::size($counts['saved']) }}</strong><span>{{ __('optimize.counts.saved') }}</span></li>
        </ul>
        @if ($settings['on'])
            <div class="ocr-progress" data-optimize-progress @if ($counts['waiting'] === 0) hidden @endif>
                <progress max="{{ max(1, $counts['done'] + $counts['waiting']) }}" value="{{ $counts['done'] }}" data-optimize-bar></progress>
                <p class="muted">{{ __('optimize.busy') }}</p>
            </div>
        @else
            <p class="card-text muted">{{ __('optimize.off_hint') }}</p>
        @endif
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="settings" />{{ __('optimize.settings') }}</h2>
        <form method="post" action="{{ route('optimize.save') }}" data-autosave>
            @csrf
            {{-- PO 2026-11-16: an Umschalter instead of the toggle switch. --}}
            <fieldset>
                <legend>{{ __('optimize.state') }}</legend>
                <span class="button-choice" data-choice>
                    <input type="hidden" name="on" value="{{ $settings['on'] ? '1' : '0' }}">
                    <span class="switcher" role="group" aria-label="{{ __('optimize.state') }}">
                        <button type="button" data-value="1" aria-pressed="{{ $settings['on'] ? 'true' : 'false' }}">{{ __('optimize.state_on') }}</button>
                        <button type="button" data-value="0" aria-pressed="{{ $settings['on'] ? 'false' : 'true' }}">{{ __('optimize.state_off') }}</button>
                    </span>
                </span>
            </fieldset>

            <div class="field">
                <label for="optimize-mb">{{ __('optimize.mb') }}</label>
                <div class="field-addon">
                    <input id="optimize-mb" name="mb" type="number" inputmode="decimal" step="0.5" min="{{ \App\Domain\Optimize\OptimizeSettings::MB_MIN }}" max="{{ \App\Domain\Optimize\OptimizeSettings::MB_MAX }}" value="{{ $settings['mb'] }}">
                    <span class="addon-end">
                        <button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('optimize.save') }}" data-tooltip="{{ __('optimize.save') }}" hidden><x-icon name="check" /></button>
                    </span>
                </div>
                <p class="field-hint">{{ __('optimize.mb_hint') }}</p>
            </div>

            <fieldset>
                <legend>{{ __('optimize.original') }}</legend>
                <span class="button-choice" data-choice>
                    <input type="hidden" name="original" value="{{ $settings['original'] }}">
                    <span class="switcher" role="group" aria-label="{{ __('optimize.original') }}">
                        @foreach (['trash' => 'trash', 'archive' => 'archive'] as $value => $icon)
                            <button type="button" data-value="{{ $value }}" aria-pressed="{{ $settings['original'] === $value ? 'true' : 'false' }}"><x-icon :name="$icon" />{{ __('optimize.original_choice.'.$value) }}</button>
                        @endforeach
                    </span>
                </span>
                <p class="field-hint">{{ __('optimize.original_hint.'.$settings['original']) }}</p>
            </fieldset>

            <div class="field">
                <label for="optimize-folder">{{ __('optimize.folder') }}</label>
                <select id="optimize-folder" name="folder">
                    @if ($settings['folder'] === null)
                        <option value="">{{ __('optimize.folder_new') }}</option>
                    @endif
                    @foreach ($folders as $row)
                        <option value="{{ $row['folder']->id }}" @selected($settings['folder'] === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                    @endforeach
                </select>
                <p class="field-hint">{{ __('optimize.folder_hint') }}</p>
            </div>
            <noscript><button type="submit">{{ __('optimize.save') }}</button></noscript>
        </form>
    </section>
@endsection
