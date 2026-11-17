<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\Proposal;
use App\Domain\Assistant\ProposalRunner;
use App\Domain\Assistant\Straightener;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use App\Http\Badges;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Vorschläge (D-040): the assistant's proposals as Empfehlungskacheln, grouped by kind – each with its reason, what it
 * names (links open the document or booking), Übernehmen / Ablehnen, a duplicate also "Das andere behalten", and
 * "Alle übernehmen" per kind – A51 "Alle sicheren übernehmen" takes only those the assistant marked sure. Taking over
 * runs as the owner (ProposalRunner); nothing happens before.
 */
final class ProposalController
{
    private const ICONS = [
        Proposal::LINK => 'link', Proposal::UNLINK => 'unlink', Proposal::TITLE => 'pencil', Proposal::MOVE => 'folder', Proposal::DUPLICATE => 'copy', Proposal::DELETE => 'trash',
        Proposal::SHRINK => 'arrows-minimize', Proposal::STRAIGHTEN => 'crop', Proposal::CORRECT => 'pencil', Proposal::SETTING => 'settings',
    ];

    public function index(PropertyValues $values): View
    {
        $open = Proposal::query()->where('status', Proposal::OPEN)->oldest()->get();
        $groups = [];
        foreach (Proposal::KINDS as $kind) {
            $mine = $open->where('kind', $kind)->values();
            if ($mine->isNotEmpty()) {
                $groups[$kind] = ['icon' => self::ICONS[$kind], 'tiles' => $mine->map(fn (Proposal $p): array => ['proposal' => $p, 'lines' => $this->lines($p, $values)])->all()];
            }
        }

        return view('assistant.proposals', ['groups' => $groups, 'count' => $open->count()]);
    }

    public function accept(Request $request, string $id, ProposalRunner $runner): RedirectResponse
    {
        $proposal = Proposal::query()->where('status', Proposal::OPEN)->whereKey($id)->firstOrFail();
        $message = $this->run($proposal, $runner, $request->boolean('swap'), $request->ip());

        return redirect()->route('proposals.index')->with($proposal->status === 'failed' ? 'status_error' : 'status', $message);
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

    public function reject(Request $request, string $id, CurrentOrg $org): RedirectResponse
    {
        $proposal = Proposal::query()->where('status', Proposal::OPEN)->whereKey($id)->firstOrFail();
        $proposal->update(['status' => 'rejected', 'decided_by' => $org->membership()->user_id, 'decided_at' => now()]);
        Badges::forget();

        return redirect()->route('proposals.index')->with('status', __('proposals.rejected'));
    }

    /** "Alle übernehmen" for one kind: each like a single click; what fails stays visible with its reason in the toast. */
    public function acceptAll(Request $request, ProposalRunner $runner): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:'.implode(',', Proposal::KINDS)], 'sure' => ['nullable', 'boolean']]);
        $done = $failed = 0;
        $open = Proposal::query()->where('status', Proposal::OPEN)->where('kind', $data['kind'])->when($request->boolean('sure'), static fn ($q) => $q->where('sure', true));
        foreach ($open->oldest()->get() as $proposal) {
            $this->run($proposal, $runner, false, $request->ip());
            $proposal->status === 'accepted' ? $done++ : $failed++;
        }

        return redirect()->route('proposals.index')->with('status', trans_choice('proposals.all_done', $done, ['count' => $done])
            .($failed > 0 ? ' '.trans_choice('proposals.all_failed', $failed, ['count' => $failed]) : ''));
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
     * What the tile shows: one line per thing it names, with a link where there is one.
     *
     * @return list<array{label: string, text: string, url: ?string}>
     */
    private function lines(Proposal $p, PropertyValues $values): array
    {
        $d = $p->payload;
        $doc = static function (string $label, ?string $id): array {
            $document = $id === null ? null : Document::query()->find($id);

            return ['label' => $label, 'text' => $document === null ? __('proposals.gone') : $document->title, 'url' => $document === null ? null : route('documents.show', $document->id)];
        };

        return match ($p->kind) {
            Proposal::LINK => [
                (static function () use ($d): array {
                    $t = Transaction::query()->find($d['transaction_id'] ?? '');

                    return ['label' => __('proposals.label.booking'), 'text' => $t === null ? __('proposals.gone')
                        : $t->booking_date->format('d.m.Y').' · '.$t->formattedAmount().' · '.($t->counterparty ?? $t->purpose ?? '–'), 'url' => $t === null ? null : route('bank.show', $t->id)];
                })(),
                $doc(__('proposals.label.document'), $d['document_id'] ?? null),
            ],
            Proposal::UNLINK => [
                (static function () use ($d): array {
                    $t = Transaction::query()->find($d['transaction_id'] ?? '');

                    return ['label' => __('proposals.label.booking'), 'text' => $t === null ? __('proposals.gone')
                        : $t->booking_date->format('d.m.Y').' · '.$t->formattedAmount().' · '.($t->counterparty ?? $t->purpose ?? '–'), 'url' => $t === null ? null : route('bank.show', $t->id)];
                })(),
                $doc(__('proposals.label.link'), $d['document_id'] ?? null),
            ],
            Proposal::TITLE => [$doc(__('proposals.label.document'), $d['document_id'] ?? null),
                ['label' => __('proposals.label.title'), 'text' => (string) ($d['title'] ?? ''), 'url' => null]],
            Proposal::MOVE => [$doc(__('proposals.label.document'), $d['document_id'] ?? null),
                ['label' => __('proposals.label.folder'), 'text' => (string) (Folder::query()->whereKey($d['folder_id'] ?? '')->value('name') ?? __('proposals.gone')), 'url' => null]],
            Proposal::DUPLICATE => [$doc(__('proposals.label.keep'), $d['keep_id'] ?? null), $doc(__('proposals.label.remove'), $d['remove_id'] ?? null)],
            Proposal::DELETE => [$doc(__('proposals.label.trash'), $d['document_id'] ?? null)],
            Proposal::STRAIGHTEN => [$doc(__('proposals.label.document'), $d['document_id'] ?? null),
                ['label' => __('proposals.label.angle'), 'text' => str_replace('.', ',', (string) round((float) ($d['angle'] ?? 0), 1)).'°'.(isset($d['w']) ? ' · '.__('proposals.label.cut') : ''), 'url' => null]],
            Proposal::SHRINK => [(static function () use ($doc, $d): array {
                $line = $doc(__('proposals.label.document'), $d['document_id'] ?? null);
                $bytes = (int) Document::query()->find($d['document_id'] ?? '')?->files()->sum('size');

                return [...$line, 'text' => $line['text'].($bytes > 0 ? ' · '.number_format($bytes / 1048576, 1, ',', '.').' MB' : '')];
            })()],
            Proposal::CORRECT => [$doc(__('proposals.label.document'), $d['document_id'] ?? null), (static function () use ($d, $values): array {
                $property = Property::query()->where('key', $d['property'] ?? '')->orWhere('name', $d['property'] ?? '')->first();
                $document = Document::query()->find($d['document_id'] ?? '');
                $now = $property === null || $document === null ? null : $document->properties()->where('property_id', $property->id)->first();

                return ['label' => $property->name ?? (string) ($d['property'] ?? ''), 'text' => ($now === null ? '–' : $values->display($now)).' → '.(($d['value'] ?? '') === '' ? '('.__('proposals.clear').')' : $d['value']), 'url' => null];
            })()],
            default => [['label' => __('proposals.label.setting'), 'text' => __('proposals.settings.'.($d['setting'] ?? '')), 'url' => null]],
        };
    }
}
