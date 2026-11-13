<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Http\OcrAccess;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrLive;
use App\Infrastructure\Environment\OcrProgress;
use App\Queue\CronClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * The OCR status poll (A21/A22, point 57) with the Live card details (point 124), and the browser's events without a
 * document (engine failed or started, paused, resumed – point 61, A61).
 */
final class OcrStatusController extends Controller
{
    /**
     * Small JSON for polling (A21/A22, point 57): the open count and bar for everyone, the details only for settings.manage.
     */
    public function status(Request $request, LocalOcr $ocr, CurrentOrg $org): JsonResponse
    {
        self::watch($org->id(), $ocr);
        $open = OcrProgress::open($org->id());
        $nav = ['waiting' => $open['waiting'], 'open' => $open['open'], 'total' => $open['total'], 'done' => $open['done']];
        // A61: why the browser engine does not start (menu tooltip); absent when it works.
        $engineError = $ocr->browserLoop() ? $ocr->browserError() : null;
        if ($engineError !== null) {
            $nav['engine_error'] = $engineError;
        }
        if (! Gate::allows(Permission::ManageSettings->value)) {
            return response()->json($nav);
        }
        $counts = OcrProgress::counts($org->id());

        $data = [
            ...$nav,
            'waiting' => $counts['waiting'],
            'mode' => $ocr->mode(),
            'counts' => $counts,
            'current' => OcrProgress::current($org->id()),
        ];
        // Point 124: the Live card asks with live=1 for the details of the current document, figures and the last ten.
        if ($request->boolean('live')) {
            $data = [...$data, ...self::live($request, $org->id(), $open['open'])];
        }

        return response()->json($data);
    }

    /** A61: the browser engine did not start; the reason is shown on the settings page and in the menu. */
    public function engineError(Request $request, LocalOcr $ocr): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $ocr->reportBrowserError((string) $data['reason']);
        OcrLog::record(app(CurrentOrg::class)->id(), 'browser', 'engine_failed', ['message' => (string) $data['reason'], 'user_id' => OcrAccess::userId($request), 'agent' => OcrLog::agent($request->userAgent())]);

        return response()->noContent();
    }

    /** Point 61: browser events without a document – engine started (with its start time), paused, resumed. */
    public function log(Request $request, CurrentOrg $org): Response
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:engine_started,paused,resumed'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:3600000'],
        ]);
        OcrLog::record($org->id(), 'browser', (string) $data['event'], [
            'duration_ms' => isset($data['duration']) ? (int) $data['duration'] : null,
            'user_id' => OcrAccess::userId($request),
            'agent' => OcrLog::agent($request->userAgent()),
        ]);

        return response()->noContent();
    }

    /**
     * Point 124 (A255-A259): what the Live card shows – the current document with its file and page, the figures from
     * the OCR log and the last ten finished documents with result and amount or sender.
     *
     * @return array{current: array<string, mixed>|null, figures: array<string, int|float|null>, recent: list<array<string, mixed>>}
     */
    public static function live(Request $request, string $orgId, ?int $open = null): array
    {
        $current = OcrProgress::current($orgId);
        if ($current !== null) {
            $file = DocumentFile::query()->where('document_id', $current['id'])->orderBy('page_no')->first();
            $current += [
                'url' => route('documents.show', $current['id']),
                'file' => $file !== null && OcrAccess::readable(DocumentFile::query()->whereKey($file->id))->exists() ? route('ocr.image', $file->id) : null,
                'type' => $file?->mime,
                'thumb' => $file?->thumb_path !== null ? route('documents.thumb', $file->id) : null,
            ];
        }
        $recent = array_map(static fn (array $row): array => [
            ...$row,
            'url' => route('documents.show', $row['id']),
            'words' => route('documents.words', $row['id']),
            'thumb' => $row['thumb'] === true && $row['file'] !== null ? route('documents.thumb', $row['file']) : null,
            // Through ocr.image, which leaves last_opened_at alone (unlike documents.file).
            'file' => $row['file'] !== null && $row['mime'] !== null && ((str_starts_with($row['mime'], 'image/') && ! in_array($row['mime'], OcrAccess::SKIP_MIMES, true)) || $row['mime'] === OcrAccess::PDF) ? route('ocr.image', $row['file']) : null,
        ], OcrLive::recent($orgId, OcrAccess::visible($request)));

        return [
            'current' => $current,
            'figures' => OcrLive::figures($orgId, $open ?? OcrProgress::open($orgId)['open']),
            'recent' => $recent,
        ];
    }

    /**
     * Point 61/A111: cron going missing or coming back (with its last run) and a switch of the engine are logged
     * once per change (checked on the status poll and the page).
     */
    public static function watch(string $orgId, LocalOcr $ocr): void
    {
        $running = CronClock::running();
        $key = 'ocr.cron.'.$orgId;
        $before = Cache::get($key);
        if ($before !== $running) {
            Cache::forever($key, $running);
            if ($before !== null || ! $running) {
                $last = CronClock::lastRun();
                OcrLog::record($orgId, 'system', $running ? 'cron_back' : 'cron_missing', [
                    'message' => $last !== null
                        ? OcrLog::text('ocr.log.cron_last', ['time' => Carbon::createFromTimestamp($last, (string) config('app.timezone'))->format('d.m.Y H:i')])
                        : OcrLog::text('ocr.log.cron_never'),
                ]);
            }
        }

        // The engine as people see it: system, app (+ PDF converter), browser, none.
        $mode = $ocr->mode();
        if ($mode === 'app' && $ocr->pdfAvailable()) {
            $mode .= '+pdf';
        }
        $modeKey = 'ocr.mode.seen.'.$orgId;
        $seen = Cache::get($modeKey);
        if ($seen !== $mode) {
            Cache::forever($modeKey, $mode);
            if (is_string($seen)) {
                OcrLog::record($orgId, 'system', 'mode_changed', ['message' => self::modeName($seen).' → '.self::modeName($mode)]);
            }
        }
    }

    private static function modeName(string $mode): string
    {
        $pdf = str_ends_with($mode, '+pdf');
        $name = OcrLog::text('ocr.mode.'.($pdf ? substr($mode, 0, -4) : $mode));

        return $pdf ? OcrLog::text('ocr.log.with_pdf', ['mode' => $name]) : $name;
    }
}
