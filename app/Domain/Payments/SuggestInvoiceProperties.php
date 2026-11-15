<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Optimize\OriginalKeeper;
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
        // Point 236: the kept original of an optimised document is no receipt of its own.
        if (OriginalKeeper::isOriginal($event->documentId)) {
            return;
        }
        try {
            $this->suggestions->suggest($event->documentId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
