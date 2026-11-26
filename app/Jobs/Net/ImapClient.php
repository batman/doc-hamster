<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Domain\Mail\Mailbox;
use App\Domain\Mail\MailboxError;

/**
 * Point 48: the few IMAP commands the Mail-Eingang needs (RFC 9051/3501), spoken over a socket – PHP 8.4 has no imap
 * extension any more and the app takes no library for it. Like UpdateSource it runs only when a person clicks
 * (or opens the Posteingang), synchronously, because cron may not be set up yet (D-020). Log in, list the mails of INBOX, fetch one, move it into a
 * folder (made when missing). Every failure is a MailboxError with a short German text for the Logbuch.
 */
final class ImapClient implements Mailbox
{
    /** @var resource|null */
    private $socket = null;

    private int $tag = 0;

    /** @var list<string> */
    private array $capabilities = [];

    /** @var non-empty-string */
    private string $delimiter = '/';

    /** @var list<string> the literals ({n} blocks) of the last command */
    private array $literals = [];

    /** @var array<string, string> wanted folder name => the name on the server */
    private array $folders = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $security,
        private readonly int $timeout = 20,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function login(string $username, string $password): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $scheme = $this->security === 'ssl' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client($scheme.'://'.$this->host.':'.$this->port, $code, $message, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailboxError((string) __('mail_inbox.errors.connect', ['host' => $this->host, 'port' => $this->port, 'message' => trim((string) preg_replace('/^.*failed:\s*/s', '', (string) $message))]));
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $greeting = $this->line();
        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new MailboxError((string) __('mail_inbox.errors.greeting'));
        }
        if ($this->security === 'starttls') {
            $this->command('STARTTLS');
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new MailboxError((string) __('mail_inbox.errors.tls'));
            }
        }
        try {
            $this->command('LOGIN '.self::quote($username).' '.self::quote($password));
        } catch (MailboxError) {
            throw new MailboxError((string) __('mail_inbox.errors.login'));
        }
        $this->capabilities = $this->capabilities();
        foreach ($this->command('LIST "" ""') as $line) {
            if (preg_match('/^\* LIST \([^)]*\) "(.)"/', $line, $match) === 1) {
                $this->delimiter = $match[1];
            }
        }
    }

    /** @return list<int> the UIDs of the mails in INBOX, oldest first */
    public function inbox(): array
    {
        $this->command('SELECT INBOX');
        $uids = [];
        foreach ($this->command('UID SEARCH ALL') as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    /** The size in bytes (to leave a giant mail alone before it fills the memory). */
    public function size(int $uid): int
    {
        foreach ($this->command('UID FETCH '.$uid.' (RFC822.SIZE)') as $line) {
            if (preg_match('/RFC822\.SIZE (\d+)/', $line, $match) === 1) {
                return (int) $match[1];
            }
        }

        return 0;
    }

    /** The whole mail as it came in; it stays unread on the server. */
    public function fetch(int $uid): string
    {
        $this->command('UID FETCH '.$uid.' (BODY.PEEK[])');
        $raw = $this->literals[0] ?? null;
        if ($raw === null) {
            throw new MailboxError((string) __('mail_inbox.errors.fetch'));
        }

        return $raw;
    }

    /** Moves the mail into the folder (made when missing), e.g. "Verarbeitet" or "Abgelehnt". */
    public function move(int $uid, string $folder): void
    {
        $target = self::quote($this->folder($folder));
        if (in_array('MOVE', $this->capabilities, true)) {
            $this->command('UID MOVE '.$uid.' '.$target);

            return;
        }
        $this->command('UID COPY '.$uid.' '.$target);
        $this->command('UID STORE '.$uid.' +FLAGS.SILENT (\\Deleted)');
        $this->command(in_array('UIDPLUS', $this->capabilities, true) ? 'UID EXPUNGE '.$uid : 'EXPUNGE');
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fwrite($this->socket, 'Z'.(++$this->tag)." LOGOUT\r\n");
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /** The folder's name on the server: an existing one with this name (also below INBOX), else it is made. */
    private function folder(string $name): string
    {
        if (isset($this->folders[$name])) {
            return $this->folders[$name];
        }
        foreach ($this->command('LIST "" "*"') as $line) {
            if (preg_match('/^\* LIST \([^)]*\) (?:"(?:\\\\.|[^"])*"|NIL) (.+)$/', $line, $match) === 1) {
                $found = trim($match[1]);
                $found = str_starts_with($found, '"') ? stripcslashes(substr($found, 1, -1)) : $found;
                $parts = explode($this->delimiter, $found);
                if (count($parts) <= 2 && strcasecmp((string) end($parts), $name) === 0) {
                    return $this->folders[$name] = $found;
                }
            }
        }
        // Some servers (Courier, many Dovecot set-ups) keep every folder below INBOX.
        foreach ([$name, 'INBOX'.$this->delimiter.$name] as $candidate) {
            try {
                $this->command('CREATE '.self::quote($candidate));
            } catch (MailboxError) {
                continue;
            }
            try {
                $this->command('SUBSCRIBE '.self::quote($candidate));
            } catch (MailboxError) {
                // Not subscribed: the folder is there all the same.
            }

            return $this->folders[$name] = $candidate;
        }
        throw new MailboxError((string) __('mail_inbox.errors.folder', ['folder' => $name]));
    }

    /** @return list<string> */
    private function capabilities(): array
    {
        foreach ($this->command('CAPABILITY') as $line) {
            if (str_starts_with($line, '* CAPABILITY ')) {
                return array_map(strtoupper(...), explode(' ', trim(substr($line, 13))));
            }
        }

        return [];
    }

    /**
     * Sends one command and reads up to its tagged answer. Literals ({n} at a line's end) are read whole and kept in
     * $this->literals.
     *
     * @return list<string> the untagged lines
     */
    private function command(string $command): array
    {
        if ($this->socket === null) {
            throw new MailboxError((string) __('mail_inbox.errors.closed'));
        }
        $tag = 'A'.str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        if (@fwrite($this->socket, $tag.' '.$command."\r\n") === false) {
            throw new MailboxError((string) __('mail_inbox.errors.closed'));
        }
        $lines = [];
        $this->literals = [];
        while (true) {
            $line = $this->line();
            if (preg_match('/\{(\d+)\}$/', $line, $match) === 1) {
                $lines[] = $line;
                $this->literals[] = $this->read((int) $match[1]);

                continue;
            }
            if (str_starts_with($line, $tag.' ')) {
                $status = substr($line, strlen($tag) + 1);
                if (! str_starts_with($status, 'OK')) {
                    throw new MailboxError((string) __('mail_inbox.errors.command', ['answer' => mb_substr($status, 0, 200)]));
                }

                return $lines;
            }
            $lines[] = $line;
        }
    }

    private function line(): string
    {
        $line = $this->socket !== null ? fgets($this->socket) : false;
        if ($line === false) {
            throw new MailboxError((string) __('mail_inbox.errors.closed'));
        }

        return rtrim($line, "\r\n");
    }

    private function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = $this->socket !== null ? fread($this->socket, max(1, min(65536, $length - strlen($data)))) : false;
            if ($chunk === false || $chunk === '') {
                throw new MailboxError((string) __('mail_inbox.errors.closed'));
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
