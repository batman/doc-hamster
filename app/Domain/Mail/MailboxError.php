<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use RuntimeException;

/** Point 48: the mailbox cannot be reached or answers no – a short German text for the Logbuch and the toast. */
final class MailboxError extends RuntimeException {}
