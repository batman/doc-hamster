<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Scanning\NetworkPrinter;
use App\Domain\Scanning\ScannerAddress;
use App\Domain\Scanning\ScanStation;
use App\Jobs\Net\ScannerDiscovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The phone app (PHONE, issue #37): what DocHamster opens from the home
 * screen. Two things only – "Belege" is the camera (photo, sent, done) and
 * "Dokumente" holds folders, documents and scanning. Everything else is the
 * website. Forms post to the website's own routes with `app_back`
 * (ReturnToApp), so there is one set of rules for both.
 */
final class PhoneAppController
{
    /** Belege: the camera, open at once. */
    public function camera(): View|RedirectResponse
    {
        if (! Gate::allows(Permission::UploadDocuments->value)) {
            return redirect()->route('app.documents');
        }

        return view('app.camera');
    }

    /** Dokumente: the folders of one level and the documents in the open folder. */
    public function documents(Request $request, CurrentOrg $org, NetworkPrinter $printer): View
    {
        $rows = collect(Folder::flat());
        $wanted = $request->query('folder');
        $current = is_string($wanted) ? $rows->first(static fn (array $row): bool => $row['folder']->id === $wanted)['folder'] ?? null : null;

        $documents = Document::query()
            ->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value))
            ->with('files')
            ->when($current !== null, static fn ($query) => $query->where('folder_id', $current?->id))
            ->latest()->limit($current !== null ? 300 : 50)->get();

        $trail = [];
        for ($folder = $current; $folder !== null; $folder = $rows->first(static fn (array $row): bool => $row['folder']->id === $folder->parent_id)['folder'] ?? null) {
            array_unshift($trail, $folder);
        }

        return view('app.documents', [
            'current' => $current,
            'trail' => $trail,
            'folders' => $rows->filter(static fn (array $row): bool => $row['folder']->parent_id === $current?->id)->pluck('folder')->values(),
            'allFolders' => $rows->all(),
            'documents' => $documents,
            'scanReady' => $this->scanReady($org),
            'canPrint' => $printer->available(),
            'back' => route('app.documents', array_filter(['folder' => $current?->id]), false),
        ]);
    }

    public function document(Request $request, string $id, CurrentOrg $org, NetworkPrinter $printer): View
    {
        $document = Document::query()
            ->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value))
            ->with('files')->whereKey($id)->firstOrFail();

        return view('app.document', [
            'document' => $document,
            'file' => $document->files->first(),
            'folders' => Folder::flat(),
            'canPrint' => $printer->available(),
            'back' => route('app.documents.show', $document->id, false),
            'list' => route('app.documents', array_filter(['folder' => $document->folder_id]), false),
        ]);
    }

    /** Scannen: one page or several, then a name and a folder. Not connected yet → connect first. */
    public function scan(Request $request, CurrentOrg $org, ScanController $scans): View|RedirectResponse
    {
        if (! $this->scanReady($org)) {
            return redirect()->route('app.scanner', array_filter(['folder' => $request->query('folder')]));
        }

        return view('app.scan', [...$scans->state($request, $org), 'folders' => Folder::flat(), 'back' => route('app.scan', array_filter(['folder' => $request->query('folder')]), false)]);
    }

    /** Connect a scanner: search the home network or link a Scan Station; only admins. */
    public function scanner(CurrentOrg $org): View
    {
        return view('app.scanner', [
            'admin' => Gate::allows(Permission::ManageSettings->value),
            'found' => session('scanners_found'),
            'code' => session('station_code'),
            'back' => route('app.scanner', absolute: false),
        ]);
    }

    public function searchScanners(ScannerDiscovery $discovery): RedirectResponse
    {
        abort_unless(Gate::allows(Permission::ManageSettings->value), 403);
        @set_time_limit(60);

        return redirect()->route('app.scanner')->with('scanners_found', $discovery->find());
    }

    /** The gear: the website (settings) and signing out. */
    public function settings(): View
    {
        return view('app.settings');
    }

    private function scanReady(CurrentOrg $org): bool
    {
        if (! Gate::allows(Permission::UploadDocuments->value)) {
            return false;
        }
        $url = $org->organization()->settings_json['scanner']['url'] ?? null;
        try {
            // The same check as the website's scanning: a stored address is not trusted blindly.
            return (is_string($url) && ScannerAddress::normalise($url) !== '') || ScanStation::linked();
        } catch (\InvalidArgumentException) {
            return ScanStation::linked();
        }
    }
}
