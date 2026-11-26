{{-- Einstellungen → Eingang → E-Mail (point 48): the mailbox the person made at their mail provider (server, port, user,
     password – stored encrypted, never shown again), "Verbindung testen", what becomes a document and who may send
     (F27 a: the people here always). Every choice saves at once (form[data-autosave]). No cron job yet: the Posteingang
     fetches with its button (A118) and when it opens (A124). JS: modules/inbox.js. --}}
@extends('layouts.settings')

@section('title', __('mail_inbox.title'))

@section('page')
    <p class="muted">{{ __('mail_inbox.intro') }}</p>

    @if (! $canManage)
        <p class="notice">{{ __('mail_inbox.manage_only') }}</p>
    @else
        <form method="post" action="{{ route('inbox.mail.save') }}" data-autosave>
            @csrf
            <section class="card">
                <h2 class="card-head"><x-icon name="mail" />{{ __('mail_inbox.mailbox') }}
                    <button type="submit" form="mail-test" class="button-small card-action"><x-icon name="plug" />{{ __('mail_inbox.test') }}</button>
                </h2>
                <fieldset>
                    <legend>{{ __('mail_inbox.state') }}</legend>
                    <span class="button-choice" data-choice>
                        <input type="hidden" name="enabled" value="{{ $inbox->enabled ? '1' : '0' }}">
                        <span class="switcher" role="group" aria-label="{{ __('mail_inbox.state') }}">
                            <button type="button" data-value="1" aria-pressed="{{ $inbox->enabled ? 'true' : 'false' }}">{{ __('mail_inbox.state_on') }}</button>
                            <button type="button" data-value="0" aria-pressed="{{ $inbox->enabled ? 'false' : 'true' }}">{{ __('mail_inbox.state_off') }}</button>
                        </span>
                    </span>
                </fieldset>
                <div class="field">
                    <label for="mail-host">{{ __('mail_inbox.host') }}</label>
                    <input id="mail-host" name="host" value="{{ $inbox->host }}" placeholder="imap.example.com" autocomplete="off" spellcheck="false">
                    <p class="field-hint">{{ __('mail_inbox.host_hint') }}</p>
                </div>
                <fieldset>
                    <legend>{{ __('mail_inbox.security') }}</legend>
                    <span class="button-choice" data-choice>
                        <input type="hidden" name="security" value="{{ $inbox->security }}">
                        <span class="switcher" role="group" aria-label="{{ __('mail_inbox.security') }}">
                            @foreach (\App\Domain\Mail\MailInbox::SECURITY as $value)
                                <button type="button" data-value="{{ $value }}" aria-pressed="{{ $inbox->security === $value ? 'true' : 'false' }}">{{ __('mail_inbox.security_choice.'.$value) }}</button>
                            @endforeach
                        </span>
                    </span>
                </fieldset>
                <div class="field">
                    <label for="mail-port">{{ __('mail_inbox.port') }}</label>
                    <input id="mail-port" name="port" type="number" inputmode="numeric" min="1" max="65535" value="{{ $inbox->port }}">
                </div>
                <div class="field">
                    <label for="mail-user">{{ __('mail_inbox.username') }}</label>
                    <input id="mail-user" name="username" value="{{ $inbox->username }}" placeholder="belege@example.com" autocomplete="off" spellcheck="false">
                </div>
                <div class="field">
                    <label for="mail-password">{{ __('mail_inbox.password') }}</label>
                    <input id="mail-password" name="password" type="password" value="" autocomplete="new-password" @if ((string) $inbox->password !== '') placeholder="{{ __('mail_inbox.password_saved') }}" @endif>
                    <p class="field-hint">{{ __('mail_inbox.password_hint') }}</p>
                </div>
            </section>

            <section class="card">
                <h2 class="card-head"><x-icon name="file-type-pdf" />{{ __('mail_inbox.processing') }}</h2>
                <fieldset>
                    <legend>{{ __('mail_inbox.with') }}</legend>
                    <span class="button-choice" data-choice>
                        <input type="hidden" name="with_attachments" value="{{ $inbox->with_attachments }}">
                        <span class="switcher" role="group" aria-label="{{ __('mail_inbox.with') }}">
                            @foreach (\App\Domain\Mail\MailInbox::WITH as $value)
                                <button type="button" data-value="{{ $value }}" aria-pressed="{{ $inbox->with_attachments === $value ? 'true' : 'false' }}">{{ __('mail_inbox.with_choice.'.$value) }}</button>
                            @endforeach
                        </span>
                    </span>
                </fieldset>
                <fieldset>
                    <legend>{{ __('mail_inbox.without') }}</legend>
                    <span class="button-choice" data-choice>
                        <input type="hidden" name="without_attachments" value="{{ $inbox->without_attachments }}">
                        <span class="switcher" role="group" aria-label="{{ __('mail_inbox.without') }}">
                            @foreach (\App\Domain\Mail\MailInbox::WITHOUT as $value)
                                <button type="button" data-value="{{ $value }}" aria-pressed="{{ $inbox->without_attachments === $value ? 'true' : 'false' }}">{{ __('mail_inbox.without_choice.'.$value) }}</button>
                            @endforeach
                        </span>
                    </span>
                </fieldset>
                <p class="field-hint">{{ __('mail_inbox.types_hint', ['mb' => \App\Domain\Mail\MailFetch::MAX_BYTES / 1048576]) }}</p>
            </section>

            <section class="card">
                <h2 class="card-head"><x-icon name="users" />{{ __('mail_inbox.senders') }}</h2>
                <p class="card-text">{{ __('mail_inbox.members') }}:
                    @if ($members === [])
                        <span class="muted">{{ __('mail_inbox.members_none') }}</span>
                    @else
                        {{ implode(', ', $members) }}
                    @endif
                </p>
                <div class="field">
                    <label for="mail-senders">{{ __('mail_inbox.senders_label') }}</label>
                    <textarea id="mail-senders" name="senders" rows="4" spellcheck="false" placeholder="max.mustermann@example.com">{{ $inbox->senders }}</textarea>
                    <p class="field-hint">{{ __('mail_inbox.senders_hint') }}</p>
                </div>
            </section>
            <noscript><button type="submit">{{ __('mail_inbox.save') }}</button></noscript>
        </form>
        <form id="mail-test" method="post" action="{{ route('inbox.mail.test') }}" data-mail-action>
            @csrf
        </form>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="refresh" />{{ __('mail_inbox.status') }}</h2>
        <p class="card-text">{{ $inbox->last_fetch_at ? __('mail_inbox.last_fetch', ['date' => $inbox->last_fetch_at->timezone(config('app.timezone'))->format('d.m.Y H:i')]) : __('mail_inbox.never') }}</p>
        @if ($inbox->last_error)
            <p class="error" role="alert">{{ __('mail_inbox.last_error', ['message' => $inbox->last_error]) }}</p>
        @endif
        <p class="card-text"><a href="{{ route('inbox.index', ['source' => 'mail']) }}" data-window>{{ __('inbox.title') }} → {{ __('inbox.sources.mail') }}</a></p>
    </section>

    <section class="card">
        <h2 class="card-head"><x-icon name="list-numbers" />{{ __('mail_inbox.how') }}</h2>
        <ol class="card-text tray-howto">
            @foreach (__('mail_inbox.how_steps') as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
    </section>
@endsection
