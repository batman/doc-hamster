<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Domain\Documents\Document;
use App\Infrastructure\Environment\LocalOcr;
use App\Jobs\Local\RecognizeText;
use App\Queue\QueueName;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The settings button "Erkennung auf dem Server einrichten" (point 18, A65): run with dispatchSync in the request, so no cron is needed; downloads live in a net job (D-020). */
final class SetUpOcr implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue(QueueName::Net->value);
    }

    public function handle(LocalOcr $ocr): void
    {
        if (($ocr->setUp(self::fetcher())['state'] ?? null) === 'ok') {
            self::queueWaiting();
        }
    }

    /** Receipts that waited for OCR get their turn now (one job each, run one at a time). */
    public static function queueWaiting(): void
    {
        Document::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'unavailable']))
            ->select('id')->eachById(static fn (Document $d) => RecognizeText::dispatch($d->id));
    }

    /** Downloads url → target and checks the pinned SHA-256; an existing good file is kept. */
    public static function fetcher(): Closure
    {
        return static function (string $url, string $sha, string $target): void {
            if (is_file($target) && hash_equals($sha, (string) hash_file('sha256', $target))) {
                return;
            }
            $tmp = $target.'.part';
            try {
                Http::timeout(600)->withOptions(['sink' => $tmp])->get($url)->throw();
                if (! hash_equals($sha, (string) hash_file('sha256', $tmp))) {
                    throw new RuntimeException('Prüfsumme falsch: '.basename($target));
                }
                if (! @rename($tmp, $target)) {
                    throw new RuntimeException('Schreiben fehlgeschlagen: '.basename($target));
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        };
    }
}
