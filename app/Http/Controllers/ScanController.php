<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Scanning\ScannerAddress;
use App\Domain\Scanning\ScanRequest;
use App\Domain\Scanning\ScanSession;
use App\Domain\Scanning\ScanStation;
use App\Jobs\Net\EsclClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Scan from a network printer or scanner into DocHamster (issue #77, D-026):
 * one page, or a whole feeder stack, or page by page from the glass, always
 * ending as ONE document in a folder. The scanner address is a setting of the
 * organisation (Admins); scanning is for everyone who may upload.
 */
final class ScanController
{
    private const DPI = [150, 200, 300];

    /** The scan page: pages so far, the form for the next one, and "Fertig". */
    public function index(Request $request, CurrentOrg $org): View
    {
        $userId = $org->membership()->user_id;
        $scanner = $this->scanner($org);
        $session = new ScanSession($org->id(), $userId);

        // A scan through a Scan Station: wait while it runs, tell the end once.
        ScanRequest::expireStale();
        $latest = ScanRequest::query()->where('user_id', $userId)->where('acknowledged', false)->orderByDesc('id')->first();
        $waiting = $latest?->isActive() ?? false;
        $ended = $latest !== null && ! $waiting ? $latest : null;
        $ended?->forceFill(['acknowledged' => true])->save();

        $station = ScanStation::online();
        $viaStation = $scanner === null && ScanStation::linked();
        $known = $station ?? ScanStation::query()->whereNotNull('token_hash')->whereNull('revoked_at')->latest('last_seen_at')->first();

        return view('scan.index', [
            'configured' => $scanner !== null || $viaStation,
            'name' => $scanner !== null ? ($org->organization()->settings_json['scanner']['name'] ?? null) : ($known->printer_name ?? $known?->name),
            'feeder' => $scanner !== null ? (bool) ($org->organization()->settings_json['scanner']['feeder'] ?? false) : (bool) $known?->printer_feeder,
            'pages' => $session->count(),
            'folders' => Folder::flat(),
            'folder' => $request->query('folder'),
            'dpi' => (int) $request->session()->get('scan_dpi', 200),
            'waiting' => $waiting,
            'failure' => $ended?->status === 'failed' ? $this->message(new RuntimeException((string) $ended->error)) : null,
            'stationOffline' => $viaStation && $station === null,
        ]);
    }

    /** Scans the next page (or the whole feeder stack) and adds it to the document in the making. */
    public function scan(Request $request, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(['platen', 'feeder'])],
            'color' => ['required', Rule::in(['color', 'gray', 'bw'])],
            'dpi' => ['required', Rule::in(self::DPI)],
        ]);
        $scanner = $this->scanner($org);
        if ($scanner === null) {
            return $this->viaStation($request, $org, $data);
        }
        @set_time_limit(180);

        try {
            $pages = (new EsclClient($scanner))->scan($data['source'], $data['color'], (int) $data['dpi']);
        } catch (RuntimeException $error) {
            return back()->withErrors(['scan' => $this->message($error)])->withInput();
        }

        (new ScanSession($org->id(), $org->membership()->user_id))->add($pages);
        $request->session()->put('scan_dpi', (int) $data['dpi']);

        return redirect()->route('scan.index', array_filter(['folder' => $request->input('folder')]))->with('status', trans_choice('scan.added', count($pages), ['count' => count($pages)]));
    }

    /**
     * No scanner address, but a Scan Station: the request waits for the station,
     * which asks every few seconds and uploads the pages (StationApiController).
     *
     * @param  array{source: string, color: string, dpi: string|int}  $data
     */
    private function viaStation(Request $request, CurrentOrg $org, array $data): RedirectResponse
    {
        $station = ScanStation::online();
        if ($station === null) {
            return back()->withErrors(['scan' => ScanStation::linked() ? __('scan.station_offline') : __('scan.not_set_up')]);
        }
        $userId = $org->membership()->user_id;
        ScanRequest::expireStale();
        if (ScanRequest::query()->where('user_id', $userId)->whereIn('status', ['queued', 'running'])->exists()) {
            return redirect()->route('scan.index', array_filter(['folder' => $request->input('folder')]));
        }

        ScanRequest::query()->create([
            'org_id' => $org->id(),
            'user_id' => $userId,
            'station_id' => $station->id,
            'source' => $data['source'],
            'color' => $data['color'],
            'dpi' => (int) $data['dpi'],
        ]);
        $request->session()->put('scan_dpi', (int) $data['dpi']);

        return redirect()->route('scan.index', array_filter(['folder' => $request->input('folder')]));
    }

    /** "Fertig": all pages become one PDF document in the chosen folder. */
    public function finish(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:250'],
            'folder' => ['nullable', 'string', Rule::in(Folder::query()->pluck('id')->all())],
        ]);
        $userId = $org->membership()->user_id;
        $session = new ScanSession($org->id(), $userId);
        $pdf = $session->toPdf((int) $request->session()->get('scan_dpi', 200));
        if ($pdf === null) {
            return back()->withErrors(['scan' => __('scan.no_pages')]);
        }

        $path = tempnam(sys_get_temp_dir(), 'dh-scan');
        file_put_contents($path, $pdf);
        try {
            $title = ($data['title'] ?? '') !== '' ? $data['title'] : __('scan.default_title', ['date' => now()->format('d.m.Y H:i')]);
            $document = DocumentIntake::store(new UploadedFile($path, 'scan.pdf', 'application/pdf', null, true), 'application/pdf', 'pdf', $org->id(), $userId, $title, folderId: $data['folder'] ?? null);
        } finally {
            File::delete($path);
        }
        $audit->record('document.scanned', orgId: $org->id(), userId: $userId, subject: $document, meta: ['pages' => $session->count()], ip: $request->ip());
        $session->discard();

        return redirect()->route('documents.show', $document->id)->with('status', __('scan.saved'));
    }

    public function cancel(CurrentOrg $org): RedirectResponse
    {
        (new ScanSession($org->id(), $org->membership()->user_id))->discard();

        return redirect()->route('scan.index');
    }

    /** One scanned page of this person, for the preview. */
    public function page(CurrentOrg $org, int $number): Response
    {
        $jpeg = (new ScanSession($org->id(), $org->membership()->user_id))->page($number);
        abort_if($jpeg === null, 404);

        return response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }

    public function remove(CurrentOrg $org, int $number): RedirectResponse
    {
        (new ScanSession($org->id(), $org->membership()->user_id))->remove($number);

        return redirect()->route('scan.index');
    }

    /** The scanner setting (Admins): a device on the network, or Scan Stations. */
    public function settings(CurrentOrg $org): View
    {
        return view('scan.settings', [
            'url' => $org->organization()->settings_json['scanner']['url'] ?? '',
            'name' => $org->organization()->settings_json['scanner']['name'] ?? null,
            'stations' => ScanStation::query()->whereNotNull('token_hash')->whereNull('revoked_at')->orderBy('name')->get(),
            'waiting' => ScanStation::query()->whereNull('token_hash')->whereNull('revoked_at')->where('pairing_expires_at', '>', now())->count(),
            'code' => session('station_code'),
            'codeName' => session('station_name'),
        ]);
    }

    /** A new Scan Station: shows its one-time code once, with the steps to start it. */
    public function stationCreate(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        $name = trim((string) ($data['name'] ?? ''));
        [$station, $code] = ScanStation::startPairing($org->membership()->user_id, $name !== '' ? $name : __('scan.station_default_name'));
        $audit->record('station.created', orgId: $org->id(), userId: $org->membership()->user_id, subject: $station, ip: $request->ip());

        return redirect()->route('scanner.show')->with('station_code', $code)->with('station_name', $station->name);
    }

    public function stationRevoke(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $station = ScanStation::query()->whereKey($id)->whereNull('revoked_at')->firstOrFail();
        $station->forceFill(['revoked_at' => now(), 'pairing_hash' => null, 'token_hash' => null])->save();
        $audit->record('station.revoked', orgId: $org->id(), userId: $org->membership()->user_id, subject: $station, ip: $request->ip());

        return redirect()->route('scanner.show')->with('status', __('scan.station_removed'));
    }

    /** The station program itself (one PHP file), for people who set a station up from this page. */
    public function stationDownload(): BinaryFileResponse
    {
        return response()->download(base_path('station/dochamster-station.php'), 'dochamster-station.php', ['Content-Type' => 'text/x-php']);
    }

    /** Saves the address after asking the scanner who it is; an address that is not a scanner is never saved. */
    public function save(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate(['url' => ['nullable', 'string', 'max:200']]);
        $organization = $org->organization();
        $settings = $organization->settings_json ?? [];

        if (trim((string) ($data['url'] ?? '')) === '') {
            unset($settings['scanner']);
            $organization->update(['settings_json' => $settings]);

            return redirect()->route('scanner.show')->with('status', __('scan.removed'));
        }

        try {
            $base = ScannerAddress::normalise((string) $data['url']);
            $capabilities = (new EsclClient($base))->capabilities();
        } catch (InvalidArgumentException|RuntimeException $error) {
            return back()->withErrors(['url' => $this->message($error)])->withInput();
        }

        $settings['scanner'] = ['url' => $base, 'name' => $capabilities['model'] !== '' ? $capabilities['model'] : $base, 'feeder' => $capabilities['feeder']];
        $organization->update(['settings_json' => $settings]);
        $audit->record('scanner.set', orgId: $org->id(), userId: $org->membership()->user_id, meta: ['model' => $settings['scanner']['name']], ip: $request->ip());

        return redirect()->route('scanner.show')->with('status', __('scan.saved_scanner', ['name' => $settings['scanner']['name']]));
    }

    private function scanner(CurrentOrg $org): ?string
    {
        $url = $org->organization()->settings_json['scanner']['url'] ?? null;
        if (! is_string($url)) {
            return null;
        }
        try {
            // Checked again on every use: a stored address is not trusted blindly.
            return ScannerAddress::normalise($url);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function message(\Throwable $error): string
    {
        $key = $error->getMessage();

        return str_starts_with($key, 'scan.') && trans($key) !== $key ? __($key) : __('scan.failed');
    }
}
