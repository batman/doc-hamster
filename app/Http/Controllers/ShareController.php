<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\ShareLink;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\OrgScope;
use App\Domain\Org\Permission;
use Illuminate\Contracts\View\View;
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

    /** The receipt behind a link, as a download. */
    public function open(string $token): Response
    {
        $link = ShareLink::query()->withoutGlobalScope(OrgScope::class)->where('token_hash', hash('sha256', $token))->first();
        abort_if($link === null || ! $link->isActive(), 404);

        $document = Document::query()->withoutGlobalScope(OrgScope::class)->with('files')->whereKey($link->document_id)->first();
        $file = $document?->files()->withoutGlobalScope(OrgScope::class)->first();
        abort_if($file === null, 404);

        return Storage::disk('local')->download($file->path, $document->title, ['Content-Type' => $file->mime]);
    }

    public function index(CurrentOrg $org): View
    {
        return view('shares.index', [
            'links' => ShareLink::query()->with('document')->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get(),
            'days' => $this->linkDays($org),
        ]);
    }

    public function days(Request $request, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'between:1,365']]);
        $organization = $org->organization();
        $organization->update(['settings_json' => [...($organization->settings_json ?? []), 'share_link_days' => (int) $data['days']]]);

        return redirect()->route('shares.index')->with('status', __('shares.saved'));
    }

    public function revoke(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $link = ShareLink::query()->whereKey($id)->firstOrFail();
        $link->update(['revoked_at' => now()]);
        $audit->record('document.share_revoked', orgId: $org->id(), userId: $org->membership()->user_id, subject: $link, ip: $request->ip());

        return redirect()->route('shares.index')->with('status', __('shares.revoked'));
    }

    private function linkDays(CurrentOrg $org): int
    {
        $days = $org->organization()->settings_json['share_link_days'] ?? null;

        return is_int($days) ? $days : ShareLink::DEFAULT_DAYS;
    }
}
