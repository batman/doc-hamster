<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Auth\User;
use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Point 48: the Mail-Eingang of one organisation (F26 a) – the IMAP mailbox, what becomes a document and who may send.
 * The password is kept encrypted (D-007) and never shown again.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $user_id who set it up: documents of senders without an account here are theirs
 * @property bool $enabled
 * @property string $host
 * @property int $port
 * @property string $security ssl|starttls|none
 * @property string $username
 * @property string|null $password encrypted
 * @property string $with_attachments attachments|mail|both
 * @property string $without_attachments mail|skip
 * @property string|null $senders one address per line, or @domain for all addresses of a domain (A125)
 * @property bool $reply_enabled A126: answer allowed senders
 * @property string|null $reply_text
 * @property string $smtp_host the outgoing server of the same mailbox (same user and password)
 * @property int $smtp_port
 * @property string $smtp_security ssl|starttls|none
 * @property Carbon|null $last_fetch_at
 * @property Carbon|null $last_success_at
 * @property string|null $last_error
 */
final class MailInbox extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const array SECURITY = ['ssl', 'starttls', 'none'];

    public const array WITH = ['attachments', 'mail', 'both'];

    public const array WITHOUT = ['mail', 'skip'];

    /** A124: opening the Posteingang fetches the mailbox by itself, at most this often. */
    public const int AUTO_MINUTES = 5;

    protected $fillable = ['org_id', 'user_id', 'enabled', 'host', 'port', 'security', 'username', 'password', 'with_attachments',
        'without_attachments', 'senders', 'reply_enabled', 'reply_text', 'smtp_host', 'smtp_port', 'smtp_security', 'last_fetch_at', 'last_success_at', 'last_error'];

    protected $hidden = ['password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'reply_enabled' => 'boolean',
            'smtp_port' => 'integer',
            'password' => 'encrypted',
            'last_fetch_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /** This organisation's mailbox (a fresh, unsaved one before it was set up). */
    public static function current(): self
    {
        return self::query()->first() ?? new self(['org_id' => app(CurrentOrg::class)->id(), 'enabled' => false, 'host' => '', 'port' => 993, 'security' => 'ssl', 'username' => '',
            'with_attachments' => 'attachments', 'without_attachments' => 'mail', 'reply_enabled' => false, 'smtp_host' => '', 'smtp_port' => 465, 'smtp_security' => 'ssl']);
    }

    public function ready(): bool
    {
        return $this->exists && $this->enabled && $this->host !== '' && $this->username !== '' && (string) $this->password !== '';
    }

    /** A126: the answer goes out – switched on, outgoing server set, and the mailbox user is an address to send from. */
    public function replyReady(): bool
    {
        return $this->ready() && $this->reply_enabled && $this->smtp_host !== '' && $this->replyFrom() !== null;
    }

    /** The address the answer comes from: the mailbox user, when it is an address. */
    public function replyFrom(): ?string
    {
        $user = mb_strtolower(trim($this->username));

        return filter_var($user, FILTER_VALIDATE_EMAIL) !== false ? $user : null;
    }

    /** A124: due for the automatic fetch when the Posteingang opens. */
    public function due(): bool
    {
        return $this->ready() && ($this->last_fetch_at === null || $this->last_fetch_at->lt(now()->subMinutes(self::AUTO_MINUTES)));
    }

    /**
     * The entry of $allowed that lets this sender in: the address itself, else its domain (A125: "@example.com").
     *
     * @param  array<string, string|null>  $allowed
     */
    public static function match(array $allowed, string $from): ?string
    {
        if ($from === '') {
            return null;
        }
        if (array_key_exists($from, $allowed)) {
            return $from;
        }
        $domain = strrchr($from, '@');

        return $domain !== false && array_key_exists($domain, $allowed) ? $domain : null;
    }

    /** @return list<string> the addresses (and @domains) entered by hand, lower case */
    public function senderList(): array
    {
        return self::addresses((string) $this->senders);
    }

    /**
     * F27 a: the people of this organisation may always send; besides them the list entered by hand.
     *
     * @return array<string, string|null> address (lower case) => user id (null: entered by hand, no account)
     */
    public function allowed(): array
    {
        $allowed = array_fill_keys($this->senderList(), null);
        $members = Membership::query()->where('org_id', $this->org_id)->whereNull('paused_at')->pluck('user_id')->all();
        foreach (User::query()->whereIn('id', $members)->whereNotNull('email')->get(['id', 'email']) as $user) {
            $allowed[mb_strtolower(trim((string) $user->email))] = (string) $user->id;
        }

        return $allowed;
    }

    /** @return list<string> the valid addresses and @domains (also written *@domain) in a text – one per line, or separated by commas, semicolons or spaces */
    public static function addresses(string $text): array
    {
        $found = [];
        foreach (preg_split('/[\s,;]+/', mb_strtolower($text)) ?: [] as $address) {
            $address = trim($address, " \t<>\"'");
            if (str_starts_with($address, '*@')) {
                $address = substr($address, 1);
            }
            $valid = str_starts_with($address, '@')
                ? filter_var('x'.$address, FILTER_VALIDATE_EMAIL) !== false && str_contains($address, '.')
                : filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
            if ($address !== '' && $valid && ! in_array($address, $found, true)) {
                $found[] = $address;
            }
        }

        return $found;
    }
}
