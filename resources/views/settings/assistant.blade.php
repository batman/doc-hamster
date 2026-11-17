{{-- Einstellungen → Assistenten (D-040, owner only): what assistants may (checkboxes, nothing ticked at first) and
     "Zugang für Assistenten" Aus | An (saves at once; An only with a right; Aus asks first, it disconnects every
     assistant), while open the address with the three clicks in Claude, and the connected assistants
     of the organisation with Trennen as red row action. --}}
@extends('layouts.settings')

@section('title', __('assistant.title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    <p class="muted">{{ __('assistant.intro') }}</p>

    {{-- Points 1, 2, A60: the assistants' proposals open from here as a window on the right; the red count leads here. --}}
    @php $proposalCount = \App\Http\Badges::counts(app(\App\Domain\Org\CurrentOrg::class))[\App\Http\Badges::PROPOSALS] ?? 0; @endphp
    <section class="card">
        <h2 class="card-head"><x-icon name="checks" />{{ __('proposals.title') }}</h2>
        <p class="card-text">{{ trans_choice('assistant.proposals_open', $proposalCount, ['count' => $proposalCount]) }}</p>
        <div class="actions">
            <a class="button" href="{{ route('proposals.index') }}" data-window><x-icon name="checks" />{{ __('proposals.open') }} <span class="badge badge-alert" data-badge="{{ \App\Http\Badges::PROPOSALS }}" @if ($proposalCount === 0) hidden @endif>{{ $proposalCount > 99 ? '99+' : $proposalCount }}</span></a>
        </div>
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="plug" />{{ __('assistant.door') }}</h2>
        <form method="post" action="{{ route('assistant.save') }}" data-autosave>
            @csrf
            {{-- PO 2026-11-17: the owner ticks each right on purpose – nothing is ticked at first; "An" only with at least one. --}}
            <fieldset>
                <legend>{{ __('assistant.rights_legend') }}</legend>
                @foreach (\App\Domain\Assistant\AssistantAccess::RIGHTS as $right)
                    <label><input type="checkbox" name="rights[]" value="{{ $right }}" @checked(in_array($right, $rights, true))> {{ __('assistant.rights.'.$right) }}</label>
                @endforeach
                @if ($rights !== [] && ! in_array('read', $rights, true))
                    <p class="field-hint">{{ __('assistant.needs_read') }}</p>
                @endif
            </fieldset>
            <fieldset>
                <legend>{{ __('assistant.state') }}</legend>
                <span class="button-choice" data-choice>
                    <input type="hidden" name="on" value="{{ $on ? '1' : '0' }}">
                    <span class="switcher" role="group" aria-label="{{ __('assistant.state') }}">
                        <button type="button" data-value="0" aria-pressed="{{ $on ? 'false' : 'true' }}"
                            data-confirm-title="{{ __('assistant.close_title') }}" data-confirm-text="{{ __('assistant.close_text') }}" data-confirm-ok="{{ __('assistant.close_ok') }}"><x-icon name="lock" />{{ __('assistant.state_off') }}</button>
                        <button type="button" data-value="1" aria-pressed="{{ $on ? 'true' : 'false' }}" @disabled($rights === [])><x-icon name="plug" />{{ __('assistant.state_on') }}</button>
                    </span>
                </span>
            </fieldset>
            <p class="field-hint">{{ $rights === [] ? __('assistant.rights_first') : ($on ? __('assistant.on_hint') : __('assistant.off_hint')) }}</p>
        </form>
    </section>

    @if ($on)
        <section class="card">
            <h2 class="card-head"><x-icon name="link" />{{ __('assistant.connect_title') }}</h2>
            @include('settings._device-copy', ['id' => 'assistant-url', 'label' => __('assistant.url'), 'value' => $mcpUrl])
            <ol class="card-text">
                @foreach (__('assistant.steps') as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </section>

        <section class="card">
            <h2 class="card-head"><x-icon name="users" />{{ __('assistant.connections') }}</h2>
            <ul class="pick-list">
                @forelse ($connections as $access)
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon name="plug" /></span>
                        <span>
                            <strong>{{ $access->name }}</strong>
                            <small>{{ $access->user?->display_name }} · {{ __('assistant.connected_at', ['date' => $access->created_at->format('d.m.Y')]) }} · {{ __('account.keys.last_used', ['date' => $access->last_used_at?->format('d.m.Y H:i') ?? __('account.keys.never')]) }}</small>
                        </span>
                        <span class="row-actions">
                            <button type="button" class="control-button control-danger" data-dialog-open="disconnect-{{ $access->id }}" aria-label="{{ __('assistant.disconnect') }}: {{ $access->name }}" data-tooltip="{{ __('assistant.disconnect') }}"><x-icon name="unlink" /></button>
                        </span>
                    </li>
                @empty
                    <x-empty tag="li" icon="plug" :text="__('assistant.none')" />
                @endforelse
            </ul>
        </section>

        @foreach ($connections as $access)
            <div class="dialog-backdrop" data-dialog="disconnect-{{ $access->id }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="disconnect-{{ $access->id }}-title">
                    <h2 id="disconnect-{{ $access->id }}-title">{{ __('assistant.disconnect_title', ['name' => $access->name]) }}</h2>
                    <p>{{ __('assistant.disconnect_text') }}</p>
                    <div class="dialog-actions">
                        <form method="post" action="{{ route('assistant.disconnect', $access->id) }}" data-confirm-sudo>
                            @csrf
                            <button type="submit" class="button-danger"><x-icon name="unlink" />{{ __('assistant.disconnect') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
@endsection
