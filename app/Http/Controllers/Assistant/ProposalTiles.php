<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\Proposal;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Illuminate\Support\Collection;

/**
 * What an Empfehlungskachel shows (points 6, 7): the documents and bookings it names as Objektkacheln (no text links),
 * the other values as label + text, and for two named things the address pair for "Nebeneinander" (A62). Documents
 * and bookings of all proposals are loaded in two queries, so filtering and searching (A57) stay cheap.
 */
final class ProposalTiles
{
    /** @var array<string, Document> */
    private array $documents = [];

    /** @var array<string, Transaction> */
    private array $bookings = [];

    /** @var array<string, int> */
    private array $amounts = [];

    /** @param  Collection<int, Proposal>  $proposals */
    public function __construct(Collection $proposals, private readonly PropertyValues $values)
    {
        $docIds = $bookingIds = [];
        foreach ($proposals as $p) {
            foreach (['document_id', 'keep_id', 'remove_id'] as $key) {
                if (is_string($p->payload[$key] ?? null)) {
                    $docIds[] = $p->payload[$key];
                }
            }
            if (is_string($p->payload['transaction_id'] ?? null)) {
                $bookingIds[] = $p->payload['transaction_id'];
            }
        }
        $docIds = array_values(array_unique($docIds));
        $this->documents = Document::query()->with('files')->whereIn('id', $docIds)->get()->keyBy('id')->all();
        $this->bookings = Transaction::query()->whereIn('id', array_values(array_unique($bookingIds)))->get()->keyBy('id')->all();
        $this->amounts = Matcher::invoiceAmounts(array_keys($this->documents));
    }

    /**
     * The things a proposal names, as Objektkacheln.
     *
     * @return list<array{label: string, title: string, meta: string, icon: string, thumb: ?string, url: ?string}>
     */
    public function objects(Proposal $p): array
    {
        $d = $p->payload;

        return match ($p->kind) {
            Proposal::LINK => [$this->document(__('proposals.label.document'), $d['document_id'] ?? null), $this->booking($d['transaction_id'] ?? null)],
            Proposal::UNLINK => [$this->document(__('proposals.label.link'), $d['document_id'] ?? null), $this->booking($d['transaction_id'] ?? null)],
            Proposal::DUPLICATE => [$this->document(__('proposals.label.keep'), $d['keep_id'] ?? null), $this->document(__('proposals.label.remove'), $d['remove_id'] ?? null)],
            Proposal::DELETE => [$this->document(__('proposals.label.trash'), $d['document_id'] ?? null)],
            Proposal::SETTING => [],
            default => [$this->document(__('proposals.label.document'), $d['document_id'] ?? null)],
        };
    }

    /**
     * The other values of a proposal (new title, folder, angle, size, a corrected value, a setting).
     *
     * @return list<array{label: string, text: string}>
     */
    public function values(Proposal $p): array
    {
        $d = $p->payload;

        return match ($p->kind) {
            Proposal::TITLE => [['label' => __('proposals.label.title'), 'text' => (string) ($d['title'] ?? '')]],
            Proposal::MOVE => [['label' => __('proposals.label.folder'), 'text' => (string) (Folder::query()->whereKey($d['folder_id'] ?? '')->value('name') ?? __('proposals.gone'))]],
            Proposal::STRAIGHTEN => [['label' => __('proposals.label.angle'), 'text' => str_replace('.', ',', (string) round((float) ($d['angle'] ?? 0), 1)).'°'.(isset($d['w']) ? ' · '.__('proposals.label.cut') : '')]],
            Proposal::SHRINK => [['label' => __('proposals.label.size'), 'text' => number_format((int) (($this->documents[$d['document_id'] ?? ''] ?? null)?->files->sum('size') ?? 0) / 1048576, 1, ',', '.').' MB']],
            Proposal::CORRECT => [$this->correction($d)],
            Proposal::SETTING => [['label' => __('proposals.label.setting'), 'text' => __('proposals.settings.'.($d['setting'] ?? ''))]],
            default => [],
        };
    }

    /** Everything a search (A57) looks through: kind, reason, assistant, the names, dates and amounts of the tiles. */
    public function haystack(Proposal $p): string
    {
        $parts = [__('proposals.kinds.'.$p->kind), __('proposals.tile.'.$p->kind), (string) $p->reason, (string) $p->assistant, (string) ($p->payload['title'] ?? ''), (string) ($p->payload['value'] ?? '')];
        foreach ($this->objects($p) as $object) {
            $parts[] = $object['title'].' '.$object['meta'];
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /** @return array{label: string, title: string, meta: string, icon: string, thumb: ?string, url: ?string} */
    private function document(string $label, mixed $id): array
    {
        $document = is_string($id) ? ($this->documents[$id] ?? null) : null;
        if ($document === null) {
            return ['label' => $label, 'title' => __('proposals.gone'), 'meta' => '', 'icon' => 'file-off', 'thumb' => null, 'url' => null];
        }
        $file = $document->files->sortBy('page_no')->first();
        $amount = $this->amounts[$document->id] ?? null;
        $meta = array_filter([$document->doc_date?->format('d.m.Y'), $amount === null ? null : Transaction::money(abs($amount))]);

        return ['label' => $label, 'title' => $document->title, 'meta' => implode(' · ', $meta), 'icon' => $file?->typeIcon() ?? 'file-text',
            'thumb' => $file?->thumb_path ? $file->thumbUrl() : null, 'url' => route('documents.show', $document->id)];
    }

    /** @return array{label: string, title: string, meta: string, icon: string, thumb: ?string, url: ?string} */
    private function booking(mixed $id): array
    {
        $t = is_string($id) ? ($this->bookings[$id] ?? null) : null;
        if ($t === null) {
            return ['label' => __('proposals.label.booking'), 'title' => __('proposals.gone'), 'meta' => '', 'icon' => 'receipt-off', 'thumb' => null, 'url' => null];
        }

        return ['label' => __('proposals.label.booking'), 'title' => $t->counterparty ?? $t->purpose ?? '–', 'meta' => $t->booking_date->format('d.m.Y').' · '.$t->signedAmount(),
            'icon' => 'receipt', 'thumb' => null, 'url' => route('bank.show', $t->id)];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{label: string, text: string}
     */
    private function correction(array $d): array
    {
        $property = Property::query()->where('key', $d['property'] ?? '')->orWhere('name', $d['property'] ?? '')->first();
        $document = $this->documents[$d['document_id'] ?? ''] ?? null;
        $now = $property === null || $document === null ? null : $document->properties()->where('property_id', $property->id)->first();
        $value = ($d['value'] ?? '') === '' ? '('.__('proposals.clear').')' : (string) $d['value'];

        return ['label' => $property->name ?? (string) ($d['property'] ?? ''), 'text' => ($now === null ? '–' : $this->values->display($now)).' → '.$value];
    }
}
