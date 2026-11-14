<?php

declare(strict_types=1);

namespace App\Http\Controllers\Advisor;

use App\Domain\Advisor\AdvisorAccess;
use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\TextDocument;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Role;
use App\Domain\ReceiptPack\ReceiptPack;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tax advisor's read-only view (point 210.5): the receipt packs of his scope, the bookings and the documents in
 * scope, and their downloads. Every view and download goes into the audit trail; the owner sees them as
 * "Zuletzt angesehen" (A380). Nothing here changes data.
 */
final class AdvisorController
{
    public const array VIEWS = ['packs', 'bookings', 'documents'];

    public function index(Request $request, CurrentOrg $org, AuditLog $audit): View
    {
        $access = $this->access($org);
        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'packs';
        $accounts = BankAccount::query()->when($access?->account_ids !== null, static fn ($q) => $q->whereIn('id', $access->account_ids ?? []))
            ->orderByRaw("kind = 'cash'")->orderBy('name')->get();
        $account = is_string($request->query('account')) && $accounts->contains('id', $request->query('account')) ? (string) $request->query('account') : null;

        $data = ['access' => $access, 'view' => $view, 'accounts' => $accounts, 'account' => $account, 'packs' => [], 'bookings' => null, 'documents' => null];
        if ($view === 'packs') {
            $data['packs'] = AdvisorAccess::packs($access, $org->id());
        } elseif ($view === 'bookings') {
            $data['bookings'] = AdvisorAccess::bookings($access)->when($account !== null, static fn ($q) => $q->where('bank_account_id', $account))
                ->with(['account', 'matches' => static fn ($m) => $m->where('state', TransactionMatch::CONFIRMED)->with('document')])
                ->orderByDesc('booking_date')->orderByDesc('id')->paginate(50)->withQueryString();
        } else {
            $data['documents'] = AdvisorAccess::documents($access)->with('files')->orderByDesc('created_at')->paginate(50)->withQueryString();
        }
        $audit->record('advisor.list_viewed', orgId: $org->id(), userId: $org->membership()->user_id, meta: ['view' => $view, 'page' => (int) $request->query('page', '1')], ip: $request->ip());

        return view('advisor.index', $data);
    }

    public function document(Request $request, string $id, CurrentOrg $org, AuditLog $audit): View
    {
        $document = AdvisorAccess::documents($this->access($org))
            ->with(['files', 'tags', 'matches' => static fn ($m) => $m->where('state', TransactionMatch::CONFIRMED)->with('transaction.account')])
            ->whereKey($id)->firstOrFail();
        $audit->record('advisor.document_viewed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, ip: $request->ip());

        return view('advisor.document', ['document' => $document]);
    }

    /** The file inline (picture in the document view) or, with ?download=1, as a download (audited). */
    public function file(Request $request, string $id, CurrentOrg $org, AuditLog $audit): Response
    {
        $file = DocumentFile::query()->whereKey($id)->firstOrFail();
        $document = AdvisorAccess::documents($this->access($org))->whereKey($file->document_id)->firstOrFail();
        if ($request->boolean('download')) {
            $audit->record('advisor.file_downloaded', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, meta: ['file' => $file->id], ip: $request->ip());

            return Storage::disk('local')->download($file->path, $file->downloadName($document->title), ['Content-Type' => $file->mime]);
        }
        $type = TextDocument::isText($file) ? 'text/plain; charset=UTF-8' : $file->mime;

        return Storage::disk('local')->response($file->path, null, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function pack(Request $request, string $id, CurrentOrg $org, AuditLog $audit): Response
    {
        $packs = collect(AdvisorAccess::packs($this->access($org), $org->id()));
        $pack = $packs->first(static fn (ReceiptPack $p): bool => $p->id === $id);
        abort_unless($pack instanceof ReceiptPack && $pack->isReady(), 404);
        $audit->record('advisor.pack_downloaded', orgId: $org->id(), userId: $org->membership()->user_id, subject: $pack, meta: ['file' => $pack->fileName()], ip: $request->ip());

        return Storage::disk('local')->download((string) $pack->path, $pack->fileName(), ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store']);
    }

    /** Only the tax advisor himself; without an access row his scope is everything (Q-20). */
    private function access(CurrentOrg $org): ?AdvisorAccess
    {
        abort_unless($org->role() === Role::Accountant, 403);

        return AdvisorAccess::forMembership($org->membership());
    }
}
