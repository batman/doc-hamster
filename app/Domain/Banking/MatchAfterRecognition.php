<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Events\TextRecognized;
use Throwable;

/**
 * A268: a recognised document is matched against the open bookings. Registered after the invoice suggestions, so
 * the amount, IBAN and sender they found are already there. A failure never fails the recognition.
 */
final class MatchAfterRecognition
{
    public function __construct(private readonly Matcher $matcher) {}

    public function handle(TextRecognized $event): void
    {
        try {
            $this->matcher->afterRecognition($event->documentId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
