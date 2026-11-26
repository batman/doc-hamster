<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Domain\Mail\MailboxError;
use App\Domain\Mail\MailInbox;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * A126: sends the answer of the Mail-Eingang through the outgoing server (SMTP) of the same mailbox, with its user and
 * password. Like ImapClient it runs only while a person clicks (or opens the Posteingang), synchronously (D-020).
 */
final class SmtpSender
{
    /** "Verbindung testen": log in at the outgoing server. */
    public static function check(MailInbox $inbox): void
    {
        $transport = self::transport($inbox);
        try {
            $transport->start();
            $transport->stop();
        } catch (Throwable $error) {
            throw self::error($inbox, $error);
        }
    }

    /** @param  array{to: string, subject: string, text: string, in_reply_to: string}  $message */
    public static function send(MailInbox $inbox, array $message): void
    {
        $email = (new Email)->from((string) $inbox->replyFrom())->to($message['to'])->subject($message['subject'])->text($message['text']);
        $headers = $email->getHeaders();
        $headers->addTextHeader('Auto-Submitted', 'auto-replied'); // RFC 3834: no answer to this answer
        $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
        if ($message['in_reply_to'] !== '') {
            $headers->addTextHeader('In-Reply-To', $message['in_reply_to']);
            $headers->addTextHeader('References', $message['in_reply_to']);
        }
        try {
            self::transport($inbox)->send($email);
        } catch (Throwable $error) {
            throw self::error($inbox, $error);
        }
    }

    private static function transport(MailInbox $inbox): EsmtpTransport
    {
        $transport = new EsmtpTransport($inbox->smtp_host, $inbox->smtp_port, $inbox->smtp_security === 'ssl');
        $transport->setRequireTls($inbox->smtp_security === 'starttls');
        $transport->setAutoTls($inbox->smtp_security !== 'none');
        $transport->setUsername($inbox->username);
        $transport->setPassword((string) $inbox->password);

        return $transport;
    }

    private static function error(MailInbox $inbox, Throwable $error): MailboxError
    {
        $text = $error->getMessage();
        if (preg_match('/\b53[05]\b|authenticat/i', $text) === 1) {
            return new MailboxError((string) __('mail_inbox.errors.smtp_login'));
        }
        if (preg_match('/connection|resolve|timed out|refused/i', $text) === 1) {
            return new MailboxError((string) __('mail_inbox.errors.smtp_connect', ['host' => $inbox->smtp_host, 'port' => $inbox->smtp_port]));
        }

        return new MailboxError((string) __('mail_inbox.errors.smtp', ['message' => mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 200)]));
    }
}
