<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\OrgScope;
use App\Infrastructure\Environment\Addon;
use App\Infrastructure\Environment\Doctor;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads the text of a receipt with the OCR add-on (tesseract, ocrmypdf), so
 * the search finds it. Runs on the local queue (no network, D-020); external
 * programs only with argument arrays (PLAN §11.2).
 */
final class RecognizeText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int TIMEOUT_SECONDS = 120;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly string $documentId)
    {
        $this->onQueue(QueueName::Local->value);
    }

    public function handle(Doctor $doctor): void
    {
        $document = Document::query()->withoutGlobalScopes()->find($this->documentId);
        if ($document === null) {
            return;
        }
        if (! $doctor->isAvailable(Addon::Ocr)) {
            $document->forceFill(['ocr_status' => 'unavailable'])->save();

            return;
        }

        $file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)
            ->where('document_id', $document->id)->orderBy('page_no')->first();
        $text = $file !== null ? self::recognize($file) : null;

        $document->forceFill([
            'ocr_text' => $text !== null ? trim($text) : null,
            'ocr_status' => $text !== null ? 'done' : 'failed',
        ])->save();
    }

    private static function recognize(DocumentFile $file): ?string
    {
        $path = Storage::disk('local')->path($file->path);
        if (! is_file($path)) {
            return null;
        }

        try {
            if ($file->isImage()) {
                return self::run(['tesseract', $path, 'stdout', '-l', 'deu+eng']);
            }
            if ($file->mime === 'application/pdf') {
                return self::pdf($path);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** --skip-text keeps pages that already have text and still writes their text to the sidecar. */
    private static function pdf(string $path): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'ocr');
        if ($base === false) {
            return null;
        }
        $sidecar = $base.'.txt';
        $out = $base.'.pdf';

        try {
            $ok = self::run(['ocrmypdf', '--sidecar', $sidecar, '--skip-text', '-l', 'deu+eng', '--quiet', $path, $out]) !== null;
            $text = $ok && is_file($sidecar) ? file_get_contents($sidecar) : false;

            return $text === false ? null : $text;
        } finally {
            foreach ([$base, $sidecar, $out] as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * @param  list<string>  $command
     */
    private static function run(array $command): ?string
    {
        $process = new Process($command, null, null, null, self::TIMEOUT_SECONDS);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
