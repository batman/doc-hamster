<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrLog;
use App\Domain\Ocr\OcrSamplePage;
use App\Domain\Ocr\OcrServerSwitch;
use App\Domain\Org\CurrentOrg;
use App\Http\OcrAccess;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use App\Jobs\Local\Ocr\Deadline;
use App\Jobs\Local\Ocr\OcrTools;
use App\Jobs\Local\RecognizeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Point 186: the server's text recognition made visible and testable – the on/off switch (186.1), "Server testen" with
 * a built-in synthetic page (186.2) and "Mit Server neu erkennen" for one document (186.3).
 */
final class OcrServerController extends Controller
{
    /** 186.2: the time limit of the test read. */
    private const int TEST_SECONDS = 90;

    /** 186.1: on/off (saves at once, data-autosave). Switched on, the documents waiting for the browser go to the server. */
    public function toggle(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $on = $request->boolean('server');
        OcrServerSwitch::save($org->organization(), $on);
        OcrLog::record($org->id(), 'system', 'server_switched', [
            'message' => OcrLog::text($on ? 'ocr.server.on' : 'ocr.server.off'),
            'user_id' => OcrAccess::userId($request),
        ]);
        if ($on) {
            Document::query()->where(static fn ($q) => $q->whereNull('ocr_status')->orWhere('ocr_status', 'pending'))
                ->select('id')->eachById(static fn (Document $d) => RecognizeText::dispatch($d->id));
        }
        OcrProgress::forgetWaiting($org->id());
        $message = __('ocr.server.saved');

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('status', $message);
    }

    /**
     * 186.2: the server reads the built-in sample page with the same steps as for a document; the answer shows the
     * recognised text next to the expected one, the duration, the word count and how many lines came out right.
     */
    public function test(Request $request, LocalOcr $ocr, CurrentOrg $org): JsonResponse
    {
        $engine = $ocr->serverEngine();
        if ($engine === null) {
            return response()->json(['message' => __('ocr.server.test_none')], 422);
        }
        set_time_limit(self::TEST_SECONDS + 30);
        $file = OcrTools::tempPng();
        imagepng(OcrSamplePage::make(), $file);
        $started = hrtime(true);
        try {
            [$text, $page] = (new OcrTools($ocr, $engine === 'app', $ocr->languages()))->tesseract($file, 1, new Deadline(self::TEST_SECONDS));
        } catch (Throwable $e) {
            $reason = OcrTools::short($e->getMessage());
            OcrLog::record($org->id(), $engine, 'server_test_failed', ['message' => $reason, 'user_id' => OcrAccess::userId($request)]);

            return response()->json(['message' => __('ocr.server.test_failed', ['reason' => $reason])], 422);
        } finally {
            OcrTools::unlink([$file]);
        }
        $ms = intdiv(hrtime(true) - $started, 1_000_000);
        $text = trim((string) preg_replace("/\n\s*\n/", "\n", $text));
        $found = self::found($text);
        $total = count(OcrSamplePage::LINES);
        OcrLog::record($org->id(), $engine, 'server_test', [
            'duration_ms' => $ms, 'chars' => mb_strlen($text), 'user_id' => OcrAccess::userId($request),
            'message' => OcrLog::text('ocr.server.test_lines', ['found' => $found, 'total' => $total]),
        ]);

        return response()->json([
            'expected' => OcrSamplePage::expected(),
            'text' => $text,
            'seconds' => number_format($ms / 1000, 1, ',', '.'),
            'words' => count($page['words'] ?? []),
            'found' => $found,
            'total' => $total,
            'ok' => $found === $total,
        ]);
    }

    /** 186.3: "Mit Server neu erkennen" – queued; the text and words are replaced when the server is done. */
    public function redo(Request $request, LocalOcr $ocr, string $id): JsonResponse|RedirectResponse
    {
        $document = OcrAccess::editable($request)->whereKey($id)->firstOrFail();
        $file = DocumentFile::query()->where('document_id', $document->id)->orderBy('page_no')->first();
        abort_unless($ocr->serverReads($file), 422);
        if ($document->getAttribute('ocr_status') !== 'done') {
            $document->forceFill(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0])->save();
        }
        RecognizeText::dispatch($document->id, false, true);
        OcrProgress::forgetWaiting($document->org_id);
        $message = __('ocr.server.redo_queued');

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('status', $message);
    }

    /** How many sample lines the text holds (spaces and case aside). */
    private static function found(string $text): int
    {
        $squash = static fn (string $s): string => mb_strtolower((string) preg_replace('/\s+/u', '', $s));
        $haystack = $squash($text);

        return count(array_filter(OcrSamplePage::LINES, static fn (string $line): bool => str_contains($haystack, $squash($line))));
    }
}
