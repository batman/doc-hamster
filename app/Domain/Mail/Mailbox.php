<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/** Point 48: what the Mail-Eingang asks of a mailbox (ImapClient; a fake one in tests). */
interface Mailbox
{
    public function login(string $username, string $password): void;

    /** @return list<int> */
    public function inbox(): array;

    public function size(int $uid): int;

    public function fetch(int $uid): string;

    public function move(int $uid, string $folder): void;

    public function close(): void;
}
