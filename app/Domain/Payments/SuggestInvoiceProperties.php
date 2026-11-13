<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Events\TextRecognized;
use Throwable;

/**
 * A253 / 122.5: after every recognition the invoice values are proposed as suggested properties. Runs in the same
 * process as the recognition (job or browser POST); a failure here never fails the recognition.
 */
final class SuggestInvoiceProperties
{
    public function __construct(private readonly InvoiceSuggestions $suggestions) {}

    public function handle(TextRecognized $event): void
    {
        try {
            $this->suggestions->suggest($event->documentId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
