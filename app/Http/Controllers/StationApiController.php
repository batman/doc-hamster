<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\Permission;
use App\Domain\Scanning\PdfBuilder;
use App\Domain\Scanning\ScanRequest;
use App\Domain\Scanning\ScanSession;
use App\Domain\Scanning\ScanStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * What a Scan Station talks to (issue #77): linked once with a one-time code,
 * then it asks for work with its token, uploads the scanned pages and reports
 * the end. No session and no cookies, so no CSRF; the token is the only key.
 * The station connects out, so nothing at home is opened to the internet.
 */
final class StationApiController
{
    /** Pages a scan may have, and the size of one page. */
    private const MAX_PAGES = 100;

    private const MAX_PAGE_BYTES = 20 * 1024 * 1024;

    /** The only errors a station may report; anything else becomes the general one. */
    private const ERRORS = ['scan.busy', 'scan.job_refused', 'scan.job_failed', 'scan.feeder_empty', 'scan.no_page', 'scan.timeout', 'scan.unreachable', 'scan.not_a_scanner'];

    public function pair(Request $request, AuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        $name = trim((string) ($data['name'] ?? ''));

        $paired = ScanStation::pair($data['code'], $name !== '' ? $name : 'Scan Station');
        abort_if($paired === null, 404);
        [$station, $token] = $paired;

        $audit->record('station.paired', orgId: $station->org_id, userId: $station->user_id, subject: $station, ip: $request->ip());

        return response()->json(['token' => $token], 201);
    }

    /**
     * "Anything to scan?": also tells the server the station is alive and which printer it has.
     * Gives the oldest waiting request (and marks it running), or 204.
     */
    public function poll(Request $request): JsonResponse|Response
    {
        $station = $this->station($request);
        $data = $request->validate([
            'printer' => ['nullable', 'array'],
            'printer.name' => ['nullable', 'string', 'max:200'],
            'printer.feeder' => ['nullable', 'boolean'],
        ]);

        $station->forceFill(['last_seen_at' => now(), ...(isset($data['printer']) ? [
            'printer_name' => $data['printer']['name'] ?? null,
            'printer_feeder' => (bool) ($data['printer']['feeder'] ?? false),
        ] : [])])->save();
        ScanRequest::expireStale();

        $next = ScanRequest::query()->withoutGlobalScope(OrgScope::class)
            ->where('station_id', $station->id)->where('status', 'queued')->oldest()->first();
        if ($next === null) {
            return response()->noContent();
        }
        // Of two polls only one takes it.
        $won = ScanRequest::query()->withoutGlobalScope(OrgScope::class)->whereKey($next->id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]);
        if ($won !== 1) {
            return response()->noContent();
        }

        return response()->json(['id' => $next->id, 'source' => $next->source, 'color' => $next->color, 'dpi' => $next->dpi]);
    }

    /** One scanned page (the raw JPEG as the request body), added to the pages of the person who asked. */
    public function page(Request $request, string $id): JsonResponse
    {
        $station = $this->station($request);
        $scan = $this->running($station, $id);
        $this->mayUpload($scan);

        $jpeg = $request->getContent();
        abort_if($jpeg === '' || strlen($jpeg) > self::MAX_PAGE_BYTES, 413);
        try {
            PdfBuilder::inspect($jpeg);
        } catch (InvalidArgumentException) {
            abort(422);
        }
        abort_if($scan->pages >= self::MAX_PAGES, 422);

        (new ScanSession($scan->org_id, $scan->user_id))->add([$jpeg]);
        $scan->forceFill(['pages' => $scan->pages + 1])->save();

        return response()->json(['pages' => $scan->pages], 201);
    }

    /** The end: with pages it is done, otherwise failed with the station's reason. */
    public function finish(Request $request, string $id): JsonResponse
    {
        $station = $this->station($request);
        $scan = $this->running($station, $id);
        $error = $request->input('error');

        if (is_string($error) && $error !== '') {
            $scan->forceFill(['status' => 'failed', 'error' => in_array($error, self::ERRORS, true) ? $error : 'scan.failed'])->save();
        } elseif ($scan->pages > 0) {
            $scan->forceFill(['status' => 'done', 'error' => null])->save();
        } else {
            $scan->forceFill(['status' => 'failed', 'error' => 'scan.no_page'])->save();
        }

        return response()->json(['status' => $scan->status]);
    }

    private function station(Request $request): ScanStation
    {
        $token = $request->bearerToken();
        $station = $token === null ? null : ScanStation::byToken($token);
        abort_if($station === null, 401);

        return $station;
    }

    /** A request of this station that is still running; anything else is not found. */
    private function running(ScanStation $station, string $id): ScanRequest
    {
        return ScanRequest::query()->withoutGlobalScope(OrgScope::class)
            ->whereKey($id)->where('station_id', $station->id)->where('status', 'running')->firstOrFail();
    }

    /** The person must still be in the organisation and allowed to upload. */
    private function mayUpload(ScanRequest $scan): void
    {
        $membership = Membership::query()->where('org_id', $scan->org_id)->where('user_id', $scan->user_id)->whereNull('revoked_at')->first();
        abort_if($membership === null, 403);
        $org = app(CurrentOrg::class);
        $org->set($membership);
        abort_unless($org->allows(Permission::UploadDocuments), 403);
    }
}
