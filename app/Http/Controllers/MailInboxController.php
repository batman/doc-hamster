<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Log\LogEntry;
use App\Domain\Mail\MailFetch;
use App\Domain\Mail\MailInbox;
use App\Domain\Org\CurrentOrg;
use App\Http\Badges;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Point 48: Einstellungen → Eingang → E-Mail (the mailbox the person made at their mail provider: server, port, user,
 * password – stored encrypted; what becomes a document; who may send) and the fetch – the button in the Posteingang
 * (A118) and the automatic one when the Posteingang opens (A124). No cron job yet.
 */
final class MailInboxController
{
    public function show(): View
    {
        $inbox = MailInbox::current();

        return view('settings.mail', [
            'inbox' => $inbox,
            'members' => array_keys(array_filter($inbox->allowed(), static fn (?string $user): bool => $user !== null)),
            'canManage' => Gate::allows('settings.manage'),
        ]);
    }

    /** Every choice saves at once (form[data-autosave]); an empty password keeps the saved one. */
    public function save(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]*$/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'security' => ['required', Rule::in(MailInbox::SECURITY)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'with_attachments' => ['required', Rule::in(MailInbox::WITH)],
            'without_attachments' => ['required', Rule::in(MailInbox::WITHOUT)],
            'senders' => ['nullable', 'string', 'max:20000'],
        ]);
        $inbox = MailInbox::current();
        $was = $inbox->exists && $inbox->enabled;
        $inbox->fill([
            'enabled' => (bool) $data['enabled'],
            'host' => mb_strtolower(trim((string) ($data['host'] ?? ''))),
            'port' => (int) ($data['port'] ?? 0) ?: ($data['security'] === 'ssl' ? 993 : 143),
            'security' => $data['security'],
            'username' => trim((string) ($data['username'] ?? '')),
            'with_attachments' => $data['with_attachments'],
            'without_attachments' => $data['without_attachments'],
            'senders' => implode("\n", MailInbox::addresses((string) ($data['senders'] ?? ''))),
        ]);
        if (($data['password'] ?? '') !== '') {
            $inbox->password = (string) $data['password'];
        }
        $inbox->user_id ??= $org->membership()->user_id;
        $inbox->save();
        if ($inbox->enabled !== $was) {
            LogEntry::write('general', 'event', (string) __($inbox->enabled ? 'mail_inbox.log.on' : 'mail_inbox.log.off'), 'mail.switched', $org->id(), $org->membership()->user_id);
        }

        return $this->answer($request, (string) __('mail_inbox.saved'));
    }

    public function test(Request $request): JsonResponse|RedirectResponse
    {
        $inbox = MailInbox::current();
        if ($inbox->host === '' || $inbox->username === '' || (string) $inbox->password === '') {
            return $this->answer($request, (string) __('mail_inbox.test_incomplete'), ['ok' => false], 422);
        }
        [$ok, $message] = MailFetch::test($inbox);

        return $this->answer($request, $message, ['ok' => $ok], $ok ? 200 : 422);
    }

    /** "Postfach abrufen" (A118); auto=1 is the fetch when the Posteingang opens (A124) – only when due, quiet otherwise. */
    public function fetch(Request $request): JsonResponse|RedirectResponse
    {
        $inbox = MailInbox::current();
        if (! $inbox->ready()) {
            return $this->answer($request, (string) __('mail_inbox.not_ready'), ['documents' => 0], 422);
        }
        if ($request->boolean('auto') && ! $inbox->due()) {
            return $this->answer($request, '', ['documents' => 0]);
        }
        $result = MailFetch::run($inbox);
        if ($result['documents'] > 0) {
            Badges::forget();
        }
        if ($result['error'] !== null) {
            return $this->answer($request, (string) __('mail_inbox.fetch_failed', ['message' => $result['error']]), $result, 422);
        }
        $message = $result['mails'] === 0
            ? (string) __('mail_inbox.fetch_none')
            : trans_choice('mail_inbox.fetch_done', $result['documents'], ['count' => $result['documents']])
                .($result['rejected'] > 0 ? ' '.trans_choice('mail_inbox.fetch_rejected', $result['rejected'], ['count' => $result['rejected']]) : '')
                .($result['more'] ? ' '.__('mail_inbox.fetch_more') : '');

        return $this->answer($request, $message, $result);
    }

    /** @param  array<string, mixed>  $extra */
    private function answer(Request $request, string $message, array $extra = [], int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, ...$extra], $status);
        }

        return $status === 200 ? back()->with('status', $message) : back()->withErrors(['mail' => $message]);
    }
}
