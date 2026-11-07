<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Scanning\ScannerAddress;
use App\Domain\Scanning\ScanStation;
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
 *
 * It works without anything at home (point 12): scanning uses the phone
 * camera, printing the phone's own print dialog with the printers in its
 * Wi-Fi. A printer/scanner connected on the website is offered as a choice.
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
    public function documents(Request $request, CurrentOrg $org): View
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
            'canScan' => Gate::allows(Permission::UploadDocuments->value),
            'back' => route('app.documents', array_filter(['folder' => $current?->id]), false),
        ]);
    }

    public function document(Request $request, string $id, CurrentOrg $org): View
    {
        $document = Document::query()
            ->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value))
            ->with('files')->whereKey($id)->firstOrFail();

        return view('app.document', [
            'document' => $document,
            'file' => $document->files->first(),
            'folders' => Folder::flat(),
            'back' => route('app.documents.show', $document->id, false),
            'list' => route('app.documents', array_filter(['folder' => $document->folder_id]), false),
        ]);
    }

    /**
     * Scannen: with the phone camera (A35), or with the printer when one is
     * connected on the website – then the person chooses once per document
     * (A39). One page or several, then a name and a folder or none.
     */
    public function scan(Request $request, CurrentOrg $org, ScanController $scans): View
    {
        abort_unless(Gate::allows(Permission::UploadDocuments->value), 403);
        $device = $this->deviceConnected($org);
        $state = $scans->state($request, $org);
        $wanted = $request->query('with');
        $with = match (true) {
            ! $device => 'camera',
            in_array($wanted, ['camera', 'printer'], true) => $wanted,
            $state['waiting'] || $state['pages'] > 0 => 'printer',
            default => null, // device connected, nothing started: ask (A39)
        };

        return view('app.scan', [
            ...$state,
            'with' => $with,
            'folders' => Folder::flat(),
        ]);
    }

    /** The gear: the website (settings) and signing out. */
    public function settings(): View
    {
        return view('app.settings');
    }

    /** A printer/scanner from the website's "Drucker & Scanner": an address in the settings or a linked Scan Station. */
    private function deviceConnected(CurrentOrg $org): bool
    {
        $url = $org->organization()->settings_json['scanner']['url'] ?? null;
        try {
            // The same check as the website's scanning: a stored address is not trusted blindly.
            return (is_string($url) && ScannerAddress::normalise($url) !== '') || ScanStation::linked();
        } catch (\InvalidArgumentException) {
            return ScanStation::linked();
        }
    }
}
