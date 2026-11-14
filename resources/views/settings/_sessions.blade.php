{{-- Point 208: Angemeldete Geräte – the person's own sign-ins (browser + system, last active, shortened IP, A374);
     "Abmelden" ends one, "Alle anderen abmelden" also rotates the remember token; both need a fresh confirmation (point 212). --}}
@php
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
                        <form method="post" action="{{ route('sessions.end', $session['id']) }}" data-confirm-sudo>
                            @csrf
                            <button type="submit" class="control-button" aria-label="{{ __('devices.sessions.end') }}: {{ $session['name'] }}" data-tooltip="{{ __('devices.sessions.end') }}"><x-icon name="power" /></button>
                        </form>
                    </span>
                @endif
            </li>
        @empty
            <x-empty tag="li" icon="device-laptop" :text="__('devices.sessions.empty')" />
        @endforelse
    </ul>
</section>

@if ($others > 0)
    <div class="dialog-backdrop" data-dialog="end-other-sessions" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="end-other-sessions-title">
            <h2 id="end-other-sessions-title">{{ __('devices.sessions.end_others_title') }}</h2>
            <form method="post" action="{{ route('sessions.others') }}" data-confirm-sudo>
                @csrf
                <p>{{ __('devices.sessions.end_others_text') }}</p>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('devices.cancel') }}</button>
                    <button type="submit" class="button-danger"><x-icon name="power" />{{ __('devices.sessions.end_others') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif
