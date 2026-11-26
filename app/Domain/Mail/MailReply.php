<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Log\LogEntry;
use App\Jobs\Net\SmtpSender;
use Closure;
use Illuminate\Support\Str;

/**
 * A126: the answer of the Mail-Eingang to an allowed sender – the text from the settings, then what arrived, what was
 * here already and what was not taken. Never to an automatic mail, a mailing list or the mailbox itself (no mail loops),
 * never to a rejected one. A failed answer is noted in the Logbuch; the fetch goes on.
 */
final class MailReply
{
    /** @var (Closure(MailInbox, array{to: string, subject: string, text: string, in_reply_to: string}): void)|null tests catch the answer here */
    public static ?Closure $send = null;

    /**
     * @param  list<string>  $received  file names stored now
     * @param  list<string>  $known  file names that were here already
     * @param  list<string>  $skipped  attachments not taken
     */
    public static function after(MailMessage $mail, MailInbox $inbox, array $received, array $known, array $skipped): bool
    {
        if (! self::wanted($mail, $inbox) || ($received === [] && $known === [] && $skipped === [])) {
            return false;
        }
        $message = [
            'to' => $mail->from,
            'subject' => Str::limit(preg_match('/^(re|aw):/i', $mail->subject) === 1 ? $mail->subject : 'Re: '.($mail->subject !== '' ? $mail->subject : __('mail_inbox.no_subject')), 200, ''),
            'text' => self::text($inbox, $received, $known, $skipped),
            'in_reply_to' => trim($mail->get('message-id')),
        ];
        try {
            self::$send !== null ? (self::$send)($inbox, $message) : SmtpSender::send($inbox, $message);
        } catch (MailboxError $error) {
            LogEntry::write('general', 'warning', (string) __('mail_inbox.log.reply_failed', ['to' => $mail->from, 'message' => $error->getMessage()]), 'mail.reply', $inbox->org_id, $inbox->user_id);

            return false;
        }

        return true;
    }

    public static function wanted(MailMessage $mail, MailInbox $inbox): bool
    {
        $auto = mb_strtolower(trim($mail->get('auto-submitted')));
        $precedence = mb_strtolower(trim($mail->get('precedence')));

        return $inbox->replyReady()
            && $mail->from !== '' && $mail->from !== $inbox->replyFrom()
            && ($auto === '' || $auto === 'no')
            && ! in_array($precedence, ['bulk', 'list', 'junk'], true)
            && $mail->get('list-id') === '' && $mail->get('x-auto-response-suppress') === ''
            && ! str_starts_with($mail->from, 'mailer-daemon@') && ! str_starts_with($mail->from, 'postmaster@')
            && ! str_contains(strstr($mail->from, '@', true) ?: '', 'noreply') && ! str_contains(strstr($mail->from, '@', true) ?: '', 'no-reply');
    }

    /**
     * @param  list<string>  $received
     * @param  list<string>  $known
     * @param  list<string>  $skipped
     */
    private static function text(MailInbox $inbox, array $received, array $known, array $skipped): string
    {
        $text = trim((string) $inbox->reply_text) !== '' ? trim((string) $inbox->reply_text) : (string) __('mail_inbox.reply_default');
        $lists = [['mail_inbox.reply_received', $received], ['mail_inbox.reply_known', $known], ['mail_inbox.reply_skipped', $skipped]];
        foreach ($lists as [$key, $names]) {
            if ($names !== []) {
                $text .= "\n\n".__($key)."\n".implode("\n", array_map(static fn (string $name): string => '- '.$name, $names));
            }
        }

        return $text."\n\n-- \ndocHamster";
    }
}
