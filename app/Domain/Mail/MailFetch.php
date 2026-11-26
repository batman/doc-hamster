<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\TextPdf;
use App\Domain\Log\LogEntry;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\PropertyValues;
use App\Jobs\Net\FetchMailInbox;
use App\Jobs\Net\ImapClient;
use App\Jobs\Net\SmtpSender;
use Closure;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Point 48: fetches the Mail-Eingang once – on the button in the Posteingang (A118), when the Posteingang opens (A124)
 * and by cron every few minutes (A128). One fetch per organisation at a time (a lock). Each mail of INBOX, oldest first:
 * - not from an allowed sender (F27 a: the people here plus the list), or forged (A120) → folder "Abgelehnt", Logbuch;
 * - else it becomes documents in the Posteingang (source "E-Mail") as the settings say – the attachments, the mail as
 *   PDF, or both – and goes into the folder "Verarbeitet". PDF, JPEG and PNG up to 20 MB; other files are named in the
 *   note. A file that is here already is not stored twice.
 */
final class MailFetch
{
    public const string DONE = 'Verarbeitet';

    public const string REJECTED = 'Abgelehnt';

    /** Per file, like every upload by e-mail or assistant. */
    public const int MAX_BYTES = 20 * 1024 * 1024;

    /** A mail this big is not loaded (the memory); it goes into "Abgelehnt" with a Logbuch entry. */
    public const int MAX_MAIL = 80 * 1024 * 1024;

    /** One click works this long, the rest follows with the next one. */
    public const int SECONDS = 40;

    public const array TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /** @var (Closure(MailInbox): Mailbox)|null tests put a fake mailbox here */
    public static ?Closure $connect = null;

    /** @var (Closure(MailInbox): void)|null tests check the outgoing server here */
    public static ?Closure $check = null;

    public static function open(MailInbox $inbox): Mailbox
    {
        $mailbox = self::$connect !== null ? (self::$connect)($inbox) : new ImapClient($inbox->host, $inbox->port, $inbox->security);
        $mailbox->login($inbox->username, (string) $inbox->password);

        return $mailbox;
    }

    /**
     * "Verbindung testen": log in and count what waits.
     *
     * @return array{bool, string}
     */
    public static function test(MailInbox $inbox): array
    {
        try {
            $mailbox = self::open($inbox);
            $count = count($mailbox->inbox());
            $mailbox->close();
            $message = trans_choice('mail_inbox.test_ok', $count, ['count' => $count]);
            if ($inbox->reply_enabled && $inbox->smtp_host !== '') {
                if ($inbox->replyFrom() === null) {
                    return [false, __('mail_inbox.test_failed', ['message' => __('mail_inbox.errors.smtp_from')])];
                }
                self::$check !== null ? (self::$check)($inbox) : SmtpSender::check($inbox);
                $message .= ' '.__('mail_inbox.test_smtp_ok');
            }

            return [true, $message];
        } catch (Throwable $error) {
            return [false, __('mail_inbox.test_failed', ['message' => self::message($error)])];
        }
    }

    /**
     * @return array{documents: int, mails: int, rejected: int, more: bool, error: string|null}
     */
    public static function run(MailInbox $inbox): array
    {
        $result = ['documents' => 0, 'mails' => 0, 'rejected' => 0, 'more' => false, 'error' => null];
        $lock = Cache::lock('mail-fetch:'.$inbox->org_id, self::SECONDS + 60);
        if (! $lock->get()) {
            return $result; // another fetch (cron or a second window) is at work
        }
        try {
            return self::fetch($inbox, $result);
        } finally {
            $lock->release();
        }
    }

    /** A128, cron every minute: hands each due Mail-Eingang to a net job. */
    public static function schedule(): int
    {
        $count = 0;
        foreach (MailInbox::query()->withoutGlobalScope(OrgScope::class)->where('enabled', true)->get() as $inbox) {
            if ($inbox->due()) {
                $inbox->forceFill(['last_fetch_at' => now()])->save();
                FetchMailInbox::dispatch($inbox->id);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array{documents: int, mails: int, rejected: int, more: bool, error: string|null}  $result
     * @return array{documents: int, mails: int, rejected: int, more: bool, error: string|null}
     */
    private static function fetch(MailInbox $inbox, array $result): array
    {
        $inbox->forceFill(['last_fetch_at' => now()])->save();
        $started = microtime(true);
        try {
            $mailbox = self::open($inbox);
        } catch (Throwable $error) {
            return self::failed($inbox, $result, $error);
        }
        try {
            $allowed = $inbox->allowed();
            foreach ($mailbox->inbox() as $uid) {
                if (microtime(true) - $started > self::SECONDS) {
                    $result['more'] = true;
                    break;
                }
                $outcome = self::one($mailbox, $uid, $inbox, $allowed);
                $result['mails']++;
                $result['documents'] += $outcome['documents'];
                $result['rejected'] += $outcome['rejected'] ? 1 : 0;
            }
            $mailbox->close();
        } catch (Throwable $error) {
            return self::failed($inbox, $result, $error);
        }
        $inbox->forceFill(['last_success_at' => now(), 'last_error' => null])->save();

        return $result;
    }

    /**
     * What one mail becomes – for the fetch and the tests.
     *
     * @param  array<string, string|null>  $allowed  address => user id
     * @return array{status: 'stored'|'rejected', reason: string, documents: list<Document>, received: list<string>, known: list<string>, skipped: list<string>}
     */
    public static function process(MailMessage $mail, MailInbox $inbox, array $allowed): array
    {
        $none = ['documents' => [], 'received' => [], 'known' => [], 'skipped' => []];
        $entry = MailInbox::match($allowed, $mail->from);
        if ($entry === null) {
            return ['status' => 'rejected', 'reason' => 'sender', ...$none];
        }
        if ($mail->authentication() === 'fail') {
            return ['status' => 'rejected', 'reason' => 'forged', ...$none];
        }
        $userId = $allowed[$entry] ?? $inbox->user_id;
        if ($userId === null) {
            return ['status' => 'rejected', 'reason' => 'nobody', ...$none];
        }

        // The attachments docHamster takes; the others are named in the note.
        $files = [];
        $skipped = [];
        foreach ($mail->attachments as $attachment) {
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($attachment['bytes']);
            if (strlen($attachment['bytes']) > self::MAX_BYTES) {
                $skipped[] = __('mail_inbox.skipped_big', ['name' => $attachment['name'], 'mb' => self::MAX_BYTES / 1048576]);
            } elseif (! isset(self::TYPES[$mime])) {
                $skipped[] = $attachment['name'];
            } else {
                $files[] = ['name' => $attachment['name'], 'mime' => $mime, 'bytes' => $attachment['bytes']];
            }
        }
        $mode = $files !== [] ? $inbox->with_attachments : $inbox->without_attachments;
        $subject = $mail->subject !== '' ? $mail->subject : (string) __('mail_inbox.no_subject');
        if (in_array($mode, ['mail', 'both'], true)) {
            array_unshift($files, ['name' => Str::slug(Str::limit($subject, 80, '')).'.pdf', 'mime' => 'application/pdf',
                'bytes' => TextPdf::make($subject, self::pdfText($mail, $files, $skipped)), 'title' => $subject]);
        }
        if ($mode === 'attachments') {
            $files = array_values(array_filter($files, static fn (array $file): bool => ! isset($file['title'])));
        }

        $note = trim(__('mail_inbox.note', [
            'from' => trim($mail->fromName !== '' ? $mail->fromName.' <'.$mail->from.'>' : $mail->from),
            'subject' => $subject,
            'date' => ($mail->date ?? now())->timezone(config('app.timezone'))->format('d.m.Y H:i'),
        ]).($skipped !== [] ? "\n".__('mail_inbox.note_skipped', ['files' => implode(', ', $skipped)]) : ''));

        $documents = [];
        $received = [];
        $known = [];
        foreach ($files as $file) {
            $label = $file['title'] ?? $file['name'];
            if (DocumentIntake::known(hash('sha256', $file['bytes'])) !== null) {
                $known[] = $label; // here already (or in the Papierkorb): never stored twice

                continue;
            }
            $document = self::store($file, $inbox, $userId, $note);
            if (isset($file['title'])) {
                self::sender($document, $mail, $allowed[$entry] !== null);
            }
            $documents[] = $document;
            $received[] = $label;
        }

        return ['status' => 'stored', 'reason' => '', 'documents' => $documents, 'received' => $received, 'known' => $known, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, string|null>  $allowed
     * @return array{documents: int, rejected: bool}
     */
    private static function one(Mailbox $mailbox, int $uid, MailInbox $inbox, array $allowed): array
    {
        try {
            if ($mailbox->size($uid) > self::MAX_MAIL) {
                LogEntry::write('general', 'warning', (string) __('mail_inbox.log.too_big', ['mb' => self::MAX_MAIL / 1048576]), 'mail.rejected', $inbox->org_id, $inbox->user_id);
                $mailbox->move($uid, self::REJECTED);

                return ['documents' => 0, 'rejected' => true];
            }
            $mail = MailMessage::parse($mailbox->fetch($uid));
            $outcome = self::process($mail, $inbox, $allowed);
        } catch (MailboxError $error) {
            throw $error; // the connection: stop this fetch
        } catch (Throwable $error) {
            // This one mail cannot be read: out of the way (Abgelehnt), noted in the Logbuch; the others go on.
            report($error);
            LogEntry::write('general', 'error', (string) __('mail_inbox.log.unreadable', ['message' => self::message($error)]), 'mail.rejected', $inbox->org_id, $inbox->user_id);
            $mailbox->move($uid, self::REJECTED);

            return ['documents' => 0, 'rejected' => true];
        }
        if ($outcome['status'] === 'rejected') {
            LogEntry::write('general', 'warning', (string) __('mail_inbox.log.rejected.'.$outcome['reason'], ['from' => $mail->from !== '' ? $mail->from : '?', 'subject' => Str::limit($mail->subject, 120)]),
                'mail.rejected', $inbox->org_id, $inbox->user_id);
            $mailbox->move($uid, self::REJECTED);

            return ['documents' => 0, 'rejected' => true];
        }
        if ($inbox->after_fetch === 'delete') {
            $mailbox->delete($uid);
        } else {
            $mailbox->move($uid, self::DONE);
        }
        MailReply::after($mail, $inbox, $outcome['received'], $outcome['known'], $outcome['skipped']);

        return ['documents' => count($outcome['documents']), 'rejected' => false];
    }

    /**
     * A130: the mail as PDF names its writer as Absender (confirmed, so the text recognition does not guess "Hallo,").
     * A mail one of the people here forwarded names the original writer from the forwarded part – or nobody.
     */
    private static function sender(Document $document, MailMessage $mail, bool $fromMember): void
    {
        [$address, $name] = $fromMember ? ($mail->forwardedFrom() ?? ['', '']) : [$mail->from, $mail->fromName];
        $sender = trim($name !== '' ? $name : $address);
        if ($sender !== '') {
            app(PropertyValues::class)->set($document, 'sender', Str::limit($sender, 180, ''));
        }
    }

    /** @param  array{name: string, mime: string, bytes: string, title?: string}  $file */
    private static function store(array $file, MailInbox $inbox, string $userId, string $note): Document
    {
        $name = Str::limit(basename(str_replace('\\', '/', $file['name'])), 200, '');
        $title = $file['title'] ?? (pathinfo($name, PATHINFO_FILENAME) ?: (string) __('mail_inbox.no_subject'));
        $path = (string) tempnam(sys_get_temp_dir(), 'dh-mail-');
        file_put_contents($path, $file['bytes']);
        try {
            $document = DocumentIntake::store(new UploadedFile($path, $name !== '' ? $name : 'mail.'.self::TYPES[$file['mime']], $file['mime'], null, true),
                $file['mime'], self::TYPES[$file['mime']], $inbox->org_id, $userId, $title, source: 'mail');
        } finally {
            @unlink($path);
        }
        $document->forceFill(['note' => $note])->saveQuietly();
        app(AuditLog::class)->record('document.uploaded_mail', orgId: $inbox->org_id, userId: $userId, subject: $document, meta: ['via' => 'mail', 'source' => 'mail', 'file' => $name]);

        return $document;
    }

    /**
     * The mail as text for its PDF: who, when, what, the attachments, then the text.
     *
     * @param  list<array{name: string, mime: string, bytes: string, title?: string}>  $files
     * @param  list<string>  $skipped
     */
    private static function pdfText(MailMessage $mail, array $files, array $skipped): string
    {
        $names = [...array_map(static fn (array $file): string => $file['name'], $files), ...$skipped];
        $head = array_filter([
            __('mail_inbox.pdf.from').': '.trim($mail->fromName !== '' ? $mail->fromName.' <'.$mail->from.'>' : $mail->from),
            $mail->to !== '' ? __('mail_inbox.pdf.to').': '.$mail->to : null,
            $mail->date !== null ? __('mail_inbox.pdf.date').': '.$mail->date->timezone(config('app.timezone'))->format('d.m.Y H:i') : null,
            $names !== [] ? __('mail_inbox.pdf.attachments').': '.implode(', ', $names) : null,
        ]);

        return implode("\n", $head)."\n\n".($mail->text !== '' ? $mail->text : __('mail_inbox.pdf.empty'));
    }

    /**
     * @param  array{documents: int, mails: int, rejected: int, more: bool, error: string|null}  $result
     * @return array{documents: int, mails: int, rejected: int, more: bool, error: string|null}
     */
    private static function failed(MailInbox $inbox, array $result, Throwable $error): array
    {
        $message = self::message($error);
        $inbox->forceFill(['last_error' => Str::limit($message, 480)])->save();
        LogEntry::write('general', 'error', (string) __('mail_inbox.log.failed', ['message' => $message]), 'mail.failed', $inbox->org_id, $inbox->user_id);

        return [...$result, 'error' => $message];
    }

    private static function message(Throwable $error): string
    {
        return $error instanceof MailboxError ? $error->getMessage() : (string) __('mail_inbox.errors.unknown');
    }
}
