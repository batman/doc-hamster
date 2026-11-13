<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document's text (and word positions) was recognised: server job, browser fallback or PDF text layer.
 * Listeners (e.g. property suggestions) read documents.ocr_text / OcrWords::forDocument().
 */
final class TextRecognized
{
    use Dispatchable;

    public function __construct(public readonly string $documentId) {}
}
