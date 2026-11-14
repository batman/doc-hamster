<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use App\Http\Controllers\Concerns\ScopesDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A38 (point 211.3): "Ins Belegpaket" in the document's ⋯ menu – a document without a booking still goes into the
 * receipt pack, in the section "Belege ohne Buchung". The same entry takes it out again.
 */
final class DocumentPackController
{
    use ScopesDocuments;

    public function __invoke(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse|JsonResponse
    {
        $document = $this->visible($request)->whereKey($id)->firstOrFail();
        $on = ! (bool) $document->getAttribute('in_receipt_pack');
        $document->forceFill(['in_receipt_pack' => $on])->save();
        $audit->record($on ? 'pack.document_added' : 'pack.document_removed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, ip: $request->ip());
        $message = __($on ? 'receipt_pack.marked' : 'receipt_pack.unmarked');

        return $request->wantsJson() ? response()->json(['message' => $message, 'on' => $on]) : back()->with('status', $message);
    }
}
