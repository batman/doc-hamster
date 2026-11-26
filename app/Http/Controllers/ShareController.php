<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\ShareLink;
use App\Domain\Documents\TextDocument;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\OrgScope;
use App\Domain\Org\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sending a receipt by link where the device's share menu isn't there (A3,
 * A4): a link without login, valid for the days set under Einstellungen,
 * revocable before. The share menu itself sends the file and needs nothing here.
 */
final class ShareController
{
    public function store(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $userId = $org->membership()->user_id;
        $document = Document::query()->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value))->whereKey($id)->firstOrFail();

        [$link, $token] = ShareLink::issue($document, $userId, $this->linkDays($org));
        $audit->record('document.shared', orgId: $org->id(), userId: $userId, subject: $document, meta: ['link' => $link->id], ip: $request->ip());

        return back()->with('share_url', route('shares.open', $token));
    }

    /**
     * The receipt behind a link (point 40, A103): a small page with the receipt shown and "Herunterladen" – a bare
     * download looked like "nothing happens" in WhatsApp's browser. The visitor is not signed in, so nothing here may
     * lean on CurrentOrg: every query names its organisation itself. Every opening lands in the audit log (PLAN §8, §11).
     */
    public function open(Request $request, string $token, AuditLog $audit): View
    {
        [$link, $document, $file] = $this->shared($token);
        $audit->record('document.share_opened', orgId: $link->org_id, subject: $document, meta: ['link' => $link->id], ip: $request->ip());

        return view('shares.open', [
            'document' => $document,
            'file' => $file,
            'token' => $token,
            'inline' => self::inline($file),
            'until' => $link->expires_at,
        ]);
    }

    /** The file itself: shown in the browser where that is safe (images, PDF, text as plain text), else a download. */
    public function file(Request $request, string $token): Response
    {
        [, $document, $file] = $this->shared($token);
        $name = $file->downloadName($document->title);
        if ($request->boolean('download') || ! self::inline($file)) {
            return Storage::disk('local')->download($file->path, $name, ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff']);
        }
        $type = TextDocument::isText($file) ? 'text/plain; charset=UTF-8' : $file->mime;

        return Storage::disk('local')->response($file->path, $name, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{ShareLink, Document, DocumentFile} */
    private function shared(string $token): array
    {
        $link = ShareLink::query()->withoutGlobalScope(OrgScope::class)->where('token_hash', hash('sha256', $token))->first();
        abort_if($link === null || ! $link->isActive(), 404);

        $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $link->org_id)->whereKey($link->document_id)->first();
        $file = $document === null ? null : DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $link->org_id)
            ->where('document_id', $document->id)->orderBy('page_no')->first();
        abort_if($document === null || $file === null || ! Storage::disk('local')->exists($file->path), 404);

        return [$link, $document, $file];
    }

    private static function inline(DocumentFile $file): bool
    {
        return in_array($file->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true) || TextDocument::isText($file);
    }

    public function index(CurrentOrg $org): View
    {
        return view('shares.index', [
            'links' => $this->visibleLinks($org)->with('document')->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get(),
            'days' => $this->linkDays($org),
            'canSetDays' => Gate::allows(Permission::ManageSettings->value),
        ]);
    }

    public function days(Request $request, CurrentOrg $org): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'between:1,365']]);
        $organization = $org->organization();
        $organization->update(['settings_json' => [...($organization->settings_json ?? []), 'share_link_days' => (int) $data['days']]]);
        // Point 170.2: one value that makes sense alone – saved with the ✓ in its field (form[data-autosave]).
        if ($request->wantsJson()) {
            return response()->json(['message' => __('shares.saved')]);
        }

        return redirect()->route('shares.index')->with('status', __('shares.saved'));
    }

    public function revoke(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $link = $this->visibleLinks($org)->whereKey($id)->firstOrFail();
        $link->update(['revoked_at' => now()]);
        $audit->record('document.share_revoked', orgId: $org->id(), userId: $org->membership()->user_id, subject: $link, ip: $request->ip());

        // A104: blocked from the document's dialog – back to the document.
        return $request->boolean('back') ? back()->with('share_dialog', true) : redirect()->route('shares.index')->with('status', __('shares.revoked'));
    }

    /**
     * Links to receipts the person may see: without "Alle Belege sehen" a link
     * to someone else's receipt would show its title (#56).
     *
     * @return Builder<ShareLink>
     */
    private function visibleLinks(CurrentOrg $org): Builder
    {
        $userId = $org->membership()->user_id;
        $seesAll = Gate::allows(Permission::ViewAllDocuments->value);

        return ShareLink::query()->whereHas('document', static fn (Builder $document) => $document->visibleTo($userId, $seesAll));
    }

    private function linkDays(CurrentOrg $org): int
    {
        $days = $org->organization()->settings_json['share_link_days'] ?? null;

        return is_int($days) ? $days : ShareLink::DEFAULT_DAYS;
    }
}
