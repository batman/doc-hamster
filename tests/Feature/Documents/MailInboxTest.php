<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Mail\Mailbox;
use App\Domain\Mail\MailboxError;
use App\Domain\Mail\MailFetch;
use App\Domain\Mail\MailInbox;
use App\Domain\Mail\MailMessage;
use App\Domain\Mail\MailReply;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Point 48: the Mail-Eingang – mails of allowed senders become documents in the Posteingang (source "E-Mail"), others
| go into "Abgelehnt". The mailbox is a fake here; the mails are synthetic.
*/
uses(RefreshDatabase::class)->group('db');

/** A mailbox in memory: uid => raw mail; moves are noted. */
final class FakeMailbox implements Mailbox
{
    /** @var array<int, string> */
    public array $mails = [];

    /** @var array<int, string> uid => folder */
    public array $moved = [];

    public bool $failLogin = false;

    public function login(string $username, string $password): void
    {
        if ($this->failLogin) {
            throw new MailboxError('Benutzer oder Passwort falsch.');
        }
    }

    public function inbox(): array
    {
        return array_values(array_diff(array_keys($this->mails), array_keys($this->moved)));
    }

    public function size(int $uid): int
    {
        return strlen($this->mails[$uid]);
    }

    public function fetch(int $uid): string
    {
        return $this->mails[$uid];
    }

    public function move(int $uid, string $folder): void
    {
        $this->moved[$uid] = $folder;
    }

    public function close(): void {}
}

function syntheticMail(string $from, string $subject, string $text, ?string $pdf = null, string $extra = ''): string
{
    $head = "From: Max Mustermann <{$from}>\r\nTo: belege@example.com\r\nSubject: {$subject}\r\nDate: Thu, 26 Nov 2026 10:15:00 +0100\r\nMIME-Version: 1.0\r\n{$extra}";
    if ($pdf === null) {
        return $head."Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($text)."\r\n";
    }

    return $head."Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\npreamble\r\n--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$text}\r\n"
        ."--b1\r\nContent-Type: application/pdf; name=\"x.pdf\"\r\nContent-Disposition: attachment; filename*=UTF-8''Rechnung%20M%C3%A4rz.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        .chunk_split(base64_encode($pdf))
        ."--b1\r\nContent-Type: application/zip; name=\"daten.zip\"\r\nContent-Disposition: attachment; filename=\"daten.zip\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        .chunk_split(base64_encode("PK\x03\x04synthetic"))."--b1--\r\n";
}

function mailSetup(Membership $owner, array $values = []): FakeMailbox
{
    test()->actingAs($owner->user)->postJson('/settings/eingang/mail', [
        'enabled' => 1, 'host' => 'imap.example.com', 'port' => 993, 'security' => 'ssl', 'username' => 'belege@example.com',
        'password' => 'synthetic-secret', 'with_attachments' => 'attachments', 'without_attachments' => 'mail', 'senders' => "partner@example.com\nkein-mail",
        ...$values,
    ])->assertOk();
    $box = new FakeMailbox;
    MailFetch::$connect = static fn (): Mailbox => $box;

    return $box;
}

beforeEach(function (): void {
    Storage::fake('local');
});

afterEach(function (): void {
    MailFetch::$connect = null;
    MailReply::$send = null;
});

it('reads a mail: sender, subject, text, attachments with RFC 2231 names, and HTML made plain', function (): void {
    $mail = MailMessage::parse(syntheticMail('MAX@example.com', '=?UTF-8?Q?Rechnung_M=C3=A4rz?=', 'Hallo, anbei die Rechnung.', "%PDF-1.4\n%synthetic\n"));

    expect($mail->from)->toBe('max@example.com')
        ->and($mail->fromName)->toBe('Max Mustermann')
        ->and($mail->subject)->toBe('Rechnung März')
        ->and($mail->text)->toBe('Hallo, anbei die Rechnung.')
        ->and($mail->date?->format('Y-m-d H:i'))->toBe('2026-11-26 10:15')
        ->and(array_column($mail->attachments, 'name'))->toBe(['Rechnung März.pdf', 'daten.zip']);

    $html = MailMessage::parse("From: a@example.com\r\nSubject: x\r\nContent-Type: text/html; charset=iso-8859-1\r\n\r\n<html><head><style>p{}</style></head><body><p>Gr\xFC\xDFe</p><p>Zeile&nbsp;2<br>Zeile 3</p></body></html>");
    expect($html->text)->toBe("Grüße\n\nZeile 2\nZeile 3");
});

it('knows a forged sender from the receiving server\'s verdict', function (): void {
    $forged = MailMessage::parse(syntheticMail('max@example.com', 'x', 'y', null, "Authentication-Results: mx.example.com; spf=fail smtp.mailfrom=example.com; dkim=none\r\n"));
    $signed = MailMessage::parse(syntheticMail('max@example.com', 'x', 'y', null, "Authentication-Results: mx.example.com; spf=softfail; dkim=pass header.d=example.com\r\n"));
    $plain = MailMessage::parse(syntheticMail('max@example.com', 'x', 'y'));

    expect($forged->authentication())->toBe('fail')
        ->and($signed->authentication())->toBe('pass')
        ->and($plain->authentication())->toBe('none');
});

it('files the attachments of allowed senders, rejects the others and never stores a file twice', function (): void {
    $owner = Membership::factory()->owner()->create();
    $box = mailSetup($owner);
    $pdf = "%PDF-1.4\n%synthetic receipt\n";
    $box->mails = [
        1 => syntheticMail((string) $owner->user->email, 'Rechnung', 'Anbei.', $pdf),
        2 => syntheticMail('fremd@example.com', 'Gewinn', 'Klicken Sie hier.'),
        3 => syntheticMail('partner@example.com', 'Noch einmal', 'Dieselbe Datei.', $pdf),
        4 => syntheticMail('partner@example.com', 'Kurze Info', 'Bitte bis Freitag zahlen.'),
    ];

    $answer = $this->postJson('/inbox/mail/fetch')->assertOk();

    expect($answer->json('documents'))->toBe(2)
        ->and($answer->json('rejected'))->toBe(1)
        ->and($box->moved)->toBe([1 => 'Verarbeitet', 2 => 'Abgelehnt', 3 => 'Verarbeitet', 4 => 'Verarbeitet']);
    $documents = Document::query()->withoutGlobalScope(OrgScope::class)->where('source', 'mail')->orderBy('title')->get();
    expect($documents->pluck('title')->all())->toBe(['Kurze Info', 'Rechnung März'])
        ->and($documents->firstWhere('title', 'Rechnung März')?->note)->toContain('Betreff „Rechnung“')->toContain('Nicht übernommen: daten.zip')
        ->and($documents->firstWhere('title', 'Rechnung März')?->uploaded_by)->toBe($owner->user_id);
    $this->get('/inbox?source=mail')->assertOk()->assertSee('Rechnung März');
});

it('makes the mail itself a PDF when asked – also next to its attachments', function (): void {
    $owner = Membership::factory()->owner()->create();
    $box = mailSetup($owner, ['with_attachments' => 'both']);
    $box->mails = [1 => syntheticMail('partner@example.com', 'Beleg Tankstelle', 'Siehe Anhang.', "%PDF-1.4\n%another\n")];

    $this->postJson('/inbox/mail/fetch')->assertOk()->assertJsonPath('documents', 2);

    expect(Document::query()->withoutGlobalScope(OrgScope::class)->where('source', 'mail')->pluck('title')->sort()->values()->all())
        ->toBe(['Beleg Tankstelle', 'Rechnung März']);
});

it('keeps the password encrypted and tells when the mailbox cannot be reached', function (): void {
    $owner = Membership::factory()->owner()->create();
    $box = mailSetup($owner);
    $box->failLogin = true;

    expect((string) DB::table('mail_inboxes')->value('password'))->not->toContain('synthetic-secret')
        ->and(MailInbox::query()->withoutGlobalScope(OrgScope::class)->first()?->password)->toBe('synthetic-secret');

    $this->postJson('/inbox/mail/fetch')->assertStatus(422)->assertJsonPath('message', 'Postfach nicht erreichbar: Benutzer oder Passwort falsch.');
    $this->postJson('/settings/eingang/mail/test')->assertStatus(422);
    $this->get('/settings/eingang/mail')->assertOk()->assertSee('Letzter Fehler')->assertDontSee('synthetic-secret');
});

it('fetches by itself when the Posteingang opens only every few minutes', function (): void {
    $owner = Membership::factory()->owner()->create();
    mailSetup($owner);

    $this->get('/inbox')->assertOk()->assertSee('data-mail-auto', false);
    $this->postJson('/inbox/mail/fetch', ['auto' => 1])->assertOk();
    $this->get('/inbox')->assertOk()->assertDontSee('data-mail-auto', false)->assertSee(__('mail_inbox.fetch'));
});

it('lets a whole domain send when the list names it (A125)', function (): void {
    expect(MailInbox::addresses("@Example.org\n*@firma.example.com\n@nodot\nmax@example.com"))->toBe(['@example.org', '@firma.example.com', 'max@example.com'])
        ->and(MailInbox::match(['@example.org' => null], 'anna@example.org'))->toBe('@example.org')
        ->and(MailInbox::match(['@example.org' => null], 'anna@sub.example.org'))->toBeNull();

    $owner = Membership::factory()->owner()->create();
    $box = mailSetup($owner, ['senders' => '@lieferant.example.com']);
    $box->mails = [1 => syntheticMail('buchhaltung@lieferant.example.com', 'Rechnung 7', 'Anbei.', "%PDF-1.4\n%domain\n"), 2 => syntheticMail('x@other.example.com', 'Nein', 'y')];

    $this->postJson('/inbox/mail/fetch')->assertOk()->assertJsonPath('documents', 1);
    expect($box->moved)->toBe([1 => 'Verarbeitet', 2 => 'Abgelehnt']);
});

it('answers allowed senders with what arrived – never rejected or automatic mails (A126)', function (): void {
    $owner = Membership::factory()->owner()->create();
    $box = mailSetup($owner, ['reply_enabled' => 1, 'reply_text' => 'Danke, ist angekommen.', 'smtp_host' => 'smtp.example.com', 'smtp_security' => 'ssl']);
    $sent = [];
    MailReply::$send = static function (MailInbox $inbox, array $message) use (&$sent): void {
        $sent[] = $message;
    };
    $pdf = "%PDF-1.4\n%reply\n";
    $box->mails = [
        1 => syntheticMail('partner@example.com', 'Beleg', 'Anbei.', $pdf, "Message-ID: <a1@example.com>\r\n"),
        2 => syntheticMail('fremd@example.com', 'Spam', 'x'),
        3 => syntheticMail('partner@example.com', 'Abwesend', 'Bin im Urlaub.', null, "Auto-Submitted: auto-replied\r\n"),
        4 => syntheticMail('partner@example.com', 'Nochmal', 'Dasselbe.', $pdf),
    ];

    $this->postJson('/inbox/mail/fetch')->assertOk();

    expect($sent)->toHaveCount(2)
        ->and($sent[0]['to'])->toBe('partner@example.com')
        ->and($sent[0]['subject'])->toBe('Re: Beleg')
        ->and($sent[0]['in_reply_to'])->toBe('<a1@example.com>')
        ->and($sent[0]['text'])->toStartWith('Danke, ist angekommen.')->toContain('- Rechnung März.pdf')->toContain('- daten.zip')
        ->and($sent[1]['text'])->toContain(__('mail_inbox.reply_known'));

    MailReply::$send = null;
});
