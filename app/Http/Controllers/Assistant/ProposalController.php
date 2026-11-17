<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\Proposal;
use App\Domain\Assistant\ProposalRunner;
use App\Domain\Assistant\Straightener;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Properties\PropertyValues;
use App\Http\Badges;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Vorschläge (D-040): the assistant's proposals as Empfehlungskacheln – since points 1–7 a window opened from
 * Einstellungen → Assistenten ("Vorschläge ansehen"), one list filtered by kind, sure/unsure and a search (A57, A58),
 * ten per page. Each tile names its documents and bookings as Objektkacheln, ✓ takes over, the bin rejects; a duplicate
 * also "Das andere behalten". "N übernehmen" / "N sichere übernehmen" take everything the filter shows (A59). Taking
 * over runs as the owner (ProposalRunner); nothing happens before. With JavaScript every action answers JSON and only
 * this window reloads, page and filter stay (A61).
 */
final class ProposalController
{
    public const PER_PAGE = 10;

    public const ICONS = [
        Proposal::LINK => 'link', Proposal::UNLINK => 'unlink', Proposal::TITLE => 'pencil', Proposal::MOVE => 'folder', Proposal::DUPLICATE => 'copy', Proposal::DELETE => 'trash',
        Proposal::SHRINK => 'arrows-minimize', Proposal::STRAIGHTEN => 'crop', Proposal::CORRECT => 'pencil', Proposal::SETTING => 'settings',
    ];

    public function index(Request $request, PropertyValues $values): View
    {
        $filter = $this->filter($request);
        $all = Proposal::query()->where('status', Proposal::OPEN)->oldest()->get();
        $kinds = [];
        foreach (Proposal::KINDS as $kind) {
            $count = $all->where('kind', $kind)->count();
            if ($count > 0) {
                $kinds[$kind] = $count;
            }
        }
        [$matching, $tiles] = $this->matching($filter, $values, $all);
        $last = max(1, (int) ceil($matching->count() / self::PER_PAGE));
        $page = max(1, min((int) $request->query('page', '1'), $last));
        $pages = new LengthAwarePaginator($matching->forPage($page, self::PER_PAGE)->values(), $matching->count(), self::PER_PAGE, $page,
            ['path' => route('proposals.index'), 'query' => array_filter($filter, static fn (string $v): bool => $v !== '')]);

        return view('assistant.proposals', ['pages' => $pages, 'tiles' => $tiles, 'kinds' => $kinds, 'filter' => $filter, 'total' => $all->count(),
            'sure' => $matching->filter(static fn (Proposal $p): bool => $p->sure === true)->count()]);
    }

    public function accept(Request $request, string $id, ProposalRunner $runner): RedirectResponse|JsonResponse
    {
        $proposal = Proposal::query()->where('status', Proposal::OPEN)->whereKey($id)->firstOrFail();
        $message = $this->run($proposal, $runner, $request->boolean('swap'), $request->ip());

        return $this->answer($request, $message, $proposal->status === 'failed');
    }

    /** Vorher / Nachher of a straighten proposal: the photo as it is, and as it would become (small JPEGs). */
    public function preview(string $id, string $side): Response
    {
        $proposal = Proposal::query()->where('kind', Proposal::STRAIGHTEN)->whereKey($id)->firstOrFail();
        $file = Document::query()->find($proposal->payload['document_id'] ?? '')?->files()->whereIn('mime', Straightener::PHOTOS)->orderBy('page_no')->first();
        abort_if($file === null, 404);
        try {
            if ($side === 'after') {
                [$angle, $box] = $proposal->straightening();
                $jpeg = Straightener::jpeg(Straightener::fit(Straightener::straighten($file, $angle, $box), 520))['jpeg'];
            } else {
                $jpeg = (string) (Straightener::look($file, 1)['jpeg'] ?? '');
            }
        } catch (RuntimeException) {
            abort(404);
        }
        abort_if($jpeg === '', 404);

        return response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=600']);
    }

    public function reject(Request $request, string $id, CurrentOrg $org): RedirectResponse|JsonResponse
    {
        $proposal = Proposal::query()->where('status', Proposal::OPEN)->whereKey($id)->firstOrFail();
        $proposal->update(['status' => 'rejected', 'decided_by' => $org->membership()->user_id, 'decided_at' => now()]);
        Badges::forget();

        return $this->answer($request, __('proposals.rejected'));
    }

    /**
     * A59: "N übernehmen" takes everything the current filter shows (all pages), "N sichere übernehmen" only those the
     * assistant marked sure – each like a single click; what fails stays visible with its reason in the toast.
     */
    public function acceptAll(Request $request, ProposalRunner $runner, PropertyValues $values): RedirectResponse|JsonResponse
    {
        $filter = $this->filter($request);
        if ($request->boolean('only_sure')) {
            $filter['sure'] = 'sure';
        }
        $done = $failed = 0;
        foreach ($this->matching($filter, $values)[0] as $proposal) {
            $this->run($proposal, $runner, false, $request->ip());
            $proposal->status === 'accepted' ? $done++ : $failed++;
        }

        return $this->answer($request, trans_choice('proposals.all_done', $done, ['count' => $done])
            .($failed > 0 ? ' '.trans_choice('proposals.all_failed', $failed, ['count' => $failed]) : ''), $done === 0 && $failed > 0);
    }

    private function run(Proposal $proposal, ProposalRunner $runner, bool $swap, ?string $ip): string
    {
        try {
            $message = $runner->accept($proposal, $swap, $ip);
            $proposal->update(['status' => 'accepted', 'result' => $message, 'decided_by' => auth()->id(), 'decided_at' => now()]);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $proposal->update(['status' => 'failed', 'result' => $message, 'decided_by' => auth()->id(), 'decided_at' => now()]);
        }
        Badges::forget();

        return $message;
    }

    /**
     * The filter of the list (A57): kind, sure|unsure and the search text; unknown values count as "Alle".
     *
     * @return array{kind: string, sure: string, q: string}
     */
    private function filter(Request $request): array
    {
        $kind = (string) $request->input('kind', '');
        $sure = (string) $request->input('sure', '');

        return ['kind' => in_array($kind, Proposal::KINDS, true) ? $kind : '', 'sure' => in_array($sure, ['sure', 'unsure'], true) ? $sure : '',
            'q' => mb_substr(trim((string) $request->input('q', '')), 0, 200)];
    }

    /**
     * The open proposals the filter lets through, oldest first, and the tiles helper that knows their documents and bookings.
     *
     * @param  array{kind: string, sure: string, q: string}  $filter
     * @param  Collection<int, Proposal>|null  $all
     * @return array{Collection<int, Proposal>, ProposalTiles}
     */
    private function matching(array $filter, PropertyValues $values, ?Collection $all = null): array
    {
        $list = ($all ?? Proposal::query()->where('status', Proposal::OPEN)->oldest()->get())
            ->filter(static fn (Proposal $p): bool => ($filter['kind'] === '' || $p->kind === $filter['kind'])
                && ($filter['sure'] === '' || ($filter['sure'] === 'sure') === ($p->sure === true)))
            ->values();
        $tiles = new ProposalTiles($list, $values);
        $words = $filter['q'] === '' ? [] : (preg_split('/\s+/', mb_strtolower($filter['q'])) ?: []);
        if ($words !== []) {
            $list = $list->filter(static function (Proposal $p) use ($tiles, $words): bool {
                $hay = $tiles->haystack($p);
                foreach ($words as $word) {
                    if (! str_contains($hay, $word)) {
                        return false;
                    }
                }

                return true;
            })->values();
        }

        return [$list, $tiles];
    }

    /** JSON for the window (message + error) or, without JavaScript, back to the list as it was (A61). */
    private function answer(Request $request, string $message, bool $error = false): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'error' => $error], $error ? 422 : 200);
        }

        // Back to the list as it was (filter, page) – but only to the list itself, never to a picture or another page.
        $previous = url()->previous();
        $list = route('proposals.index');
        $to = $previous === $list || str_starts_with($previous, $list.'?') ? $previous : $list;

        return redirect()->to($to)->with($error ? 'status_error' : 'status', $message);
    }
}
