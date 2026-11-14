{{-- Point 208: Angemeldete Geräte – the person's own sign-ins (browser + system, last active, shortened IP, A374);
     "Abmelden" ends one at once, "Alle anderen abmelden" asks for the password and also rotates the remember token. --}}
@php
    $sessionErrors = $errors->getBag('sessions');
    $others = collect($sessions)->where('current', false)->count();
@endphp
<section class="card">
    <h2 class="card-head"><x-icon name="lock" />{{ __('devices.sessions.title') }}
        @if ($others > 0)
            <span class="card-action">
                <button type="button" class="button-small" data-dialog-open="end-other-sessions"><x-icon name="power" />{{ __('devices.sessions.end_others') }}</button>
            </span>
        @endif
    </h2>
    <p class="card-note">{{ __('devices.sessions.note') }}</p>
    <ul class="pick-list">
        @forelse ($sessions as $session)
            <li class="pick-row is-static">
                <span class="row-icon"><x-icon :name="$session['mobile'] ? 'device-mobile' : 'device-laptop'" /></span>
                <span>
                    <strong>{{ $session['name'] }}</strong>
                    <small>{{ __('devices.sessions.last_active', ['ago' => $session['ago'], 'at' => $session['at']]) }}@if ($session['ip'] !== null) · {{ __('devices.sessions.ip', ['ip' => $session['ip']]) }}@endif</small>
                </span>
                @if ($session['current'])
                    <span class="pill pill-ok">{{ __('devices.sessions.current') }}</span>
                @else
                    <span class="row-actions">
                        <form method="post" action="{{ route('sessions.end', $session['id']) }}">
                            @csrf
                            <button type="submit" class="control-button" aria-label="{{ __('devices.sessions.end') }}: {{ $session['name'] }}" data-tooltip="{{ __('devices.sessions.end') }}"><x-icon name="power" /></button>
                        </form>
                    </span>
                @endif
            </li>
        @empty
            <li class="list-empty">{{ __('devices.sessions.empty') }}</li>
        @endforelse
    </ul>
</section>

@if ($others > 0)
    <div class="dialog-backdrop" data-dialog="end-other-sessions" @unless ($sessionErrors->any()) hidden @endunless>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="end-other-sessions-title">
            <h2 id="end-other-sessions-title">{{ __('devices.sessions.end_others_title') }}</h2>
            <form method="post" action="{{ route('sessions.others') }}">
                @csrf
                <p>{{ __('devices.sessions.end_others_text') }}</p>
                @if ($sessionPassword)
                    <div class="field">
                        <label for="end-other-sessions-password">{{ __('devices.sessions.password') }}</label>
                        <input id="end-other-sessions-password" type="password" name="password" autocomplete="current-password" required>
                    </div>
                    @if ($sessionErrors->has('password'))<p class="error" role="alert">{{ $sessionErrors->first('password') }}</p>@endif
                @endif
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('devices.cancel') }}</button>
                    <button type="submit" class="button-danger"><x-icon name="power" />{{ __('devices.sessions.end_others') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif
