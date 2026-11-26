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
                <fieldset>
                    <legend>{{ __('mail_inbox.after') }}</legend>
                    <span class="button-choice" data-choice>
                        <input type="hidden" name="after_fetch" value="{{ $inbox->after_fetch }}">
                        <span class="switcher" role="group" aria-label="{{ __('mail_inbox.after') }}">
                            @foreach (\App\Domain\Mail\MailInbox::AFTER as $value)
                                <button type="button" data-value="{{ $value }}" aria-pressed="{{ $inbox->after_fetch === $value ? 'true' : 'false' }}">{{ __('mail_inbox.after_choice.'.$value) }}</button>
                            @endforeach
                        </span>
                    </span>
                    <p class="field-hint">{{ __('mail_inbox.after_hint') }}</p>
                </fieldset>
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

            <section class="card">
                <h2 class="card-head"><x-icon name="send" />{{ __('mail_inbox.reply') }}</h2>
                <fieldset>
                    <legend>{{ __('mail_inbox.reply_state') }}</legend>
                    <span class="button-choice" data-choice>
                        <input type="hidden" name="reply_enabled" value="{{ $inbox->reply_enabled ? '1' : '0' }}">
                        <span class="switcher" role="group" aria-label="{{ __('mail_inbox.reply_state') }}">
                            <button type="button" data-value="1" aria-pressed="{{ $inbox->reply_enabled ? 'true' : 'false' }}">{{ __('mail_inbox.state_on') }}</button>
                            <button type="button" data-value="0" aria-pressed="{{ $inbox->reply_enabled ? 'false' : 'true' }}">{{ __('mail_inbox.state_off') }}</button>
                        </span>
                    </span>
                </fieldset>
                <div class="field">
                    <label for="mail-reply-text">{{ __('mail_inbox.reply_text') }}</label>
                    <textarea id="mail-reply-text" name="reply_text" rows="3" placeholder="{{ __('mail_inbox.reply_default') }}">{{ $inbox->reply_text }}</textarea>
                    <p class="field-hint">{{ __('mail_inbox.reply_hint') }}</p>
                </div>
                <div class="field">
                    <label for="mail-smtp-host">{{ __('mail_inbox.smtp_host') }}</label>
                    <input id="mail-smtp-host" name="smtp_host" value="{{ $inbox->smtp_host }}" placeholder="smtp.example.com" autocomplete="off" spellcheck="false">
                    <p class="field-hint">{{ __('mail_inbox.smtp_hint') }}</p>
                </div>
                <fieldset>
                    <legend>{{ __('mail_inbox.security') }}</legend>
                    <span class="button-choice" data-choice>
                        <input type="hidden" name="smtp_security" value="{{ $inbox->smtp_security }}">
                        <span class="switcher" role="group" aria-label="{{ __('mail_inbox.security') }}">
                            @foreach (\App\Domain\Mail\MailInbox::SECURITY as $value)
                                <button type="button" data-value="{{ $value }}" aria-pressed="{{ $inbox->smtp_security === $value ? 'true' : 'false' }}">{{ __('mail_inbox.security_choice.'.$value) }}</button>
                            @endforeach
                        </span>
                    </span>
                </fieldset>
                <div class="field">
                    <label for="mail-smtp-port">{{ __('mail_inbox.port') }}</label>
                    <input id="mail-smtp-port" name="smtp_port" type="number" inputmode="numeric" min="1" max="65535" value="{{ $inbox->smtp_port }}">
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
        <p class="card-text">{{ __('mail_inbox.auto_hint', ['minutes' => \App\Domain\Mail\MailInbox::AUTO_MINUTES]) }}</p>
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
