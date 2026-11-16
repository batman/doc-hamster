{{-- One person of the Personen page (points 215, 218, 223): head (initials, name, "benutzername · zuletzt aktiv …", pills)
     and – for everyone but oneself – the body with label/value lines. --}}
@php
    $user = $membership->user;
    $name = (string) ($user?->display_name ?? '?');
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(count($words) > 1 ? mb_substr((string) end($words), 0, 1) : ''));
    $self = $membership->user_id === $selfId;
    $at = $lastActive[$membership->user_id] ?? null;
    $active = $at !== null ? __('members.last_active', ['when' => \Carbon\CarbonImmutable::createFromTimestamp((int) $at)->locale(app()->getLocale())->diffForHumans()]) : __('members.never_active');
    $from = $membership->access_from;
    $until = $membership->access_until;
    $period = match (true) {
        $from !== null && $until !== null => $from->format('d.m.').'–'.$until->format('d.m.'),
        $from !== null => __('roles.since', ['date' => $from->format('d.m.')]),
        $until !== null => __('roles.until', ['date' => $until->format('d.m.')]),
        default => null,
    };
    $noAccess = $user !== null && $user->password_hash === null && ! $user->passkeys()->exists();
    $isAdmin = $membership->role === \App\Domain\Org\Role::Owner;
    $roleValue = $isAdmin ? $adminValue : (string) $membership->org_role_id;
    $roleOptions = [$adminValue => __('members.roles.owner'), ...$roles->pluck('name', 'id')->all()];
    // PO 2026-11-16: the symbols of the Rollen rows – Admin a star, every role the shield.
    $roleIcons = array_map(static fn ($key): string => $key === $adminValue ? 'star' : 'user-shield', array_combine(array_keys($roleOptions), array_keys($roleOptions)));
    $paused = $membership->isPaused();
@endphp
<li @class(['accordion-row', 'is-open' => $isOpen && ! $self]) id="person-{{ $membership->id }}">
    <div class="accordion-head">
        @if ($self)
            <span class="accordion-static">
                <span class="initials">{{ $initials }}</span>
                <span><strong>{{ $name }}</strong><small>{{ $user?->username }} · {{ $active }}</small></span>
                <span class="accordion-pills"><span class="pill">{{ __('members.self') }}</span><span @class(['pill', 'pill-new' => $isAdmin])>{{ $membership->roleName() }}</span></span>
            </span>
        @else
            <button type="button" class="list-entry accordion-toggle" data-accordion-toggle aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="person-body-{{ $membership->id }}">
                <span @class(['initials', 'is-paused' => $paused])>{{ $initials }}</span>
                <span><strong>{{ $name }}</strong><small>{{ $user?->username }} · {{ $active }}</small></span>
                <span class="accordion-pills">
                    <span @class(['pill', 'pill-new' => $isAdmin]) data-role-pill>{{ $membership->roleName() }}</span>
                    <span class="pill" data-paused-pill @unless ($paused) hidden @endunless>{{ __('members.paused_pill') }}</span>
                    @if ($period !== null)<span class="pill">{{ $period }}</span>@endif
                    {{-- A378: restored from a backup without any way to sign in – a new one-time password sets it up again. --}}
                    @if ($noAccess)<span class="pill pill-warning">{{ __('members.no_access') }}</span>@endif
                </span>
            </button>
        @endif
    </div>
    @unless ($self)
        <div class="accordion-body" id="person-body-{{ $membership->id }}" @unless ($isOpen) hidden @endunless>
            <ul class="prop-list">
                <li class="prop-row">
                    <span class="prop-name">{{ __('members.role') }}</span>
                    <span class="prop-value">
                        <form method="post" action="{{ route('members.role', $membership->id) }}" data-autosave>
                            @csrf
                            @include('documents._search-choice', ['name' => 'role', 'value' => $roleValue, 'options' => $roleOptions, 'icons' => $roleIcons, 'toggle' => false, 'label' => __('members.role')])
                        </form>
                    </span>
                </li>
                <li class="prop-row">
                    <span class="prop-name">{{ __('members.access_from') }} / {{ __('members.access_until') }}</span>
                    <span class="prop-value">
                        <form method="post" action="{{ route('members.access', $membership->id) }}" data-autosave>
                            @csrf
                            <input class="input-small" type="date" name="access_from" value="{{ $from?->toDateString() }}" aria-label="{{ __('members.access_from') }}">
                            <input class="input-small" type="date" name="access_until" value="{{ $until?->toDateString() }}" aria-label="{{ __('members.access_until') }}">
                            @if ($from === null && $until === null)<small class="muted">{{ __('members.unlimited') }}</small>@endif
                        </form>
                    </span>
                </li>
                <li class="prop-row">
                    <span class="prop-name">{{ __('members.password') }}</span>
                    <span class="prop-value"><button type="button" class="button-link" data-dialog-open="person-reset-{{ $membership->id }}" data-tooltip="{{ __('members.new_password') }}"><x-icon name="refresh" />{{ __('members.new_password') }}</button></span>
                </li>
                <li class="prop-row">
                    <span class="prop-name">{{ __('members.status') }}</span>
                    <span class="prop-value">
                        {{-- PO 2026-11-16: an Umschalter instead of the toggle switch; "Pausiert" asks first. --}}
                        <form method="post" action="{{ route('members.pause', $membership->id) }}" data-autosave>
                            @csrf
                            @php $isPaused = $membership->paused_at !== null; @endphp
                            <span class="button-choice" data-choice>
                                <input type="hidden" name="paused" value="{{ $isPaused ? '1' : '0' }}">
                                <span class="switcher" role="group" aria-label="{{ __('members.status') }}" data-tooltip="{{ __('members.paused_hint') }}">
                                    <button type="button" data-value="0" aria-pressed="{{ $isPaused ? 'false' : 'true' }}"><x-icon name="player-play" />{{ __('members.active') }}</button>
                                    <button type="button" data-value="1" aria-pressed="{{ $isPaused ? 'true' : 'false' }}"
                                        data-confirm-title="{{ __('members.pause_title') }}" data-confirm-text="{{ __('members.pause_text', ['name' => $name]) }}" data-confirm-ok="{{ __('members.pause_ok') }}"><x-icon name="player-pause" />{{ __('members.paused') }}</button>
                                </span>
                            </span>
                        </form>
                    </span>
                </li>
                <li class="prop-row">
                    <span class="prop-name">{{ __('members.recent') }}</span>
                    <span class="prop-value">
                        @if (($recent[$membership->id] ?? []) === [])
                            <small class="muted">{{ __('members.recent_none') }}</small>
                        @else
                            <small>@foreach ($recent[$membership->id] as $view){{ $view['title'] }} ({{ __('members.actions.'.$view['action']) }}, {{ $view['at']->format('d.m. H:i') }})@unless ($loop->last) · @endunless @endforeach</small>
                        @endif
                    </span>
                </li>
            </ul>
            <p><button type="button" class="button-link is-danger" data-dialog-open="person-revoke-{{ $membership->id }}" data-tooltip="{{ __('members.revoke') }}"><x-icon name="trash" />{{ __('members.revoke') }}</button></p>
        </div>

        <div class="dialog-backdrop" data-dialog="person-reset-{{ $membership->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="person-reset-{{ $membership->id }}-title">
                <h2 id="person-reset-{{ $membership->id }}-title">{{ __('members.new_password_title') }}</h2>
                <p>{{ __('members.new_password_text', ['name' => $name]) }}</p>
                <form method="post" action="{{ route('members.reset', $membership->id) }}" class="dialog-actions" data-confirm-sudo>
                    @csrf
                    <button type="submit"><x-icon name="refresh" />{{ __('members.new_password') }}</button>
                </form>
            </div>
        </div>
        <div class="dialog-backdrop" data-dialog="person-revoke-{{ $membership->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="person-revoke-{{ $membership->id }}-title">
                <h2 id="person-revoke-{{ $membership->id }}-title">{{ __('members.revoke_title') }}</h2>
                <p>{{ __('members.revoke_text', ['name' => $name]) }}</p>
                <form method="post" action="{{ route('members.revoke', $membership->id) }}" class="dialog-actions" data-confirm-sudo>
                    @csrf
                    <button type="submit" class="button-danger"><x-icon name="trash" />{{ __('members.revoke') }}</button>
                </form>
            </div>
        </div>
    @endunless
</li>
