<?php

declare(strict_types=1);

namespace App\Domain\Org;

use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\ReceiptPack\PackContents;
use App\Domain\ReceiptPack\ReceiptPack;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a restricted role may see (point 215.3). Every part is optional (null = no restriction) and all apply together
 * (A387): a period (documents by Belegdatum, bookings by booking date), bank accounts incl. cash boxes and folder
 * branches (a ticked folder includes its subfolders). Enforced centrally: Document::visibleTo() (every document list,
 * search, file, thumbnail and download), the global scopes on Transaction and BankAccount (RestrictedScope) and the
 * receipt packs (A386). A folder restriction hides the Posteingang (A384) and every folder outside the branches except
 * the path to them (F39, RestrictedScope on Folder).
 */
final class RoleRestriction
{
    /** @var list<string>|null */
    private ?array $branch = null;

    /** @var list<string>|null */
    private ?array $visibleFolders = null;

    /**
     * @param  list<string>|null  $accountIds
     * @param  list<string>|null  $folderIds
     */
    public function __construct(
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
        public readonly ?array $accountIds = null,
        public readonly ?array $folderIds = null,
    ) {}

    public static function of(?OrgRole $role): self
    {
        if ($role === null) {
            return new self;
        }

        return new self($role->period_from, $role->period_to, self::ids($role->account_ids), self::ids($role->folder_ids));
    }

    /** The restriction of the current request (none outside a request or for Admins). */
    public static function current(): self
    {
        return app(CurrentOrg::class)->restriction();
    }

    /**
     * @return list<string>|null
     */
    private static function ids(mixed $ids): ?array
    {
        return is_array($ids) ? array_values(array_map('strval', $ids)) : null;
    }

    public function isEmpty(): bool
    {
        return $this->from === null && $this->to === null && $this->accountIds === null && $this->folderIds === null;
    }

    /** A384: a role that sees only some folders does not see the Posteingang (documents without a folder). */
    public function hidesInbox(): bool
    {
        return $this->folderIds !== null;
    }

    /**
     * The ticked folders and every folder below them.
     *
     * @return list<string>
     */
    public function folderBranch(): array
    {
        if ($this->folderIds === null) {
            return [];
        }
        if ($this->branch !== null) {
            return $this->branch;
        }
        $children = Folder::query()->withoutGlobalScope(RestrictedScope::class)->get(['id', 'parent_id'])->groupBy(static fn (Folder $f): string => (string) $f->parent_id);
        $ids = [];
        $walk = static function (string $id) use (&$walk, &$ids, $children): void {
            if (in_array($id, $ids, true)) {
                return;
            }
            $ids[] = $id;
            foreach ($children->get($id, []) as $child) {
                $walk($child->id);
            }
        };
        foreach ($this->folderIds as $id) {
            $walk($id);
        }

        return $this->branch = $ids;
    }

    /**
     * F39: the folders a folder-restricted role may see at all – its branches and the folders above them as the path
     * to them (no siblings). Folder carries RestrictedScope, so every tree, menu, picker and breadcrumb follows.
     *
     * @return list<string>
     */
    public function visibleFolders(): array
    {
        if ($this->folderIds === null) {
            return [];
        }
        if ($this->visibleFolders !== null) {
            return $this->visibleFolders;
        }
        $parents = Folder::query()->withoutGlobalScope(RestrictedScope::class)->pluck('parent_id', 'id');
        $ids = $this->folderBranch();
        foreach ($this->folderIds as $id) {
            $parent = $parents->get($id);
            while (is_string($parent) && ! in_array($parent, $ids, true)) {
                $ids[] = $parent;
                $parent = $parents->get($parent);
            }
        }

        return $this->visibleFolders = $ids;
    }

    /**
     * F39: whether documents may be filed into the folder (null = no folder). The folders above a branch only show
     * the way; a restricted role files into its branches.
     */
    public function allowsFolderTarget(?string $id): bool
    {
        return $this->folderIds === null || ($id !== null && in_array($id, $this->folderBranch(), true));
    }

    /** F39: where a document without a folder lands for a folder-restricted role (it has no Posteingang): its first ticked folder. */
    public function homeFolder(): ?string
    {
        return $this->folderIds[0] ?? null;
    }

    /**
     * F39: whether the role may change the folder itself (rename, move, delete, order): only folders below its ticked
     * ones – the ticked folders and the path above them stay as the Admin set them.
     */
    public function allowsFolderChange(?string $id): bool
    {
        return $this->folderIds === null || ($id !== null && ! in_array($id, $this->folderIds, true) && in_array($id, $this->folderBranch(), true));
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function folders(Builder $query): void
    {
        if ($this->folderIds !== null) {
            $query->whereIn($query->getModel()->qualifyColumn('id'), $this->visibleFolders());
        }
    }

    /**
     * Documents: in the period (Belegdatum), in the folder branches, and – with an account restriction – linked to a
     * booking of an allowed account (F41: a document without a booking is hidden as well).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function documents(Builder $query): Builder
    {
        if ($this->isEmpty()) {
            return $query;
        }
        $date = Document::dateSql('doc');
        if ($this->from !== null) {
            $query->whereRaw($date.' >= ?', [$this->from->toDateString()]);
        }
        if ($this->to !== null) {
            $query->whereRaw($date.' <= ?', [$this->to->toDateString()]);
        }
        if ($this->folderIds !== null) {
            $query->whereIn($query->getModel()->qualifyColumn('folder_id'), $this->folderBranch());
        }
        if ($this->accountIds !== null) {
            $allowed = Transaction::query()->withoutGlobalScope(RestrictedScope::class)->select('id')->whereIn('bank_account_id', $this->accountIds);
            $confirmed = static fn (Builder $m) => $m->where('state', TransactionMatch::CONFIRMED);
            // F41: only documents linked to a booking of an allowed account; one without a booking is hidden too.
            $query->whereHas('matches', static fn (Builder $m) => $confirmed($m)->whereIn('transaction_id', $allowed));
        }

        return $query;
    }

    /**
     * Bookings: in the period and on the allowed accounts.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function bookings(Builder $query): void
    {
        $model = $query->getModel();
        if ($this->from !== null) {
            $query->whereDate($model->qualifyColumn('booking_date'), '>=', $this->from->toDateString());
        }
        if ($this->to !== null) {
            $query->whereDate($model->qualifyColumn('booking_date'), '<=', $this->to->toDateString());
        }
        if ($this->accountIds !== null) {
            $query->whereIn($model->qualifyColumn('bank_account_id'), $this->accountIds);
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function accounts(Builder $query): void
    {
        if ($this->accountIds !== null) {
            $query->whereIn($query->getModel()->qualifyColumn('id'), $this->accountIds);
        }
    }

    /**
     * A386: a pack only when it lies inside the restriction – its accounts allowed, its period inside, and every
     * document in it visible.
     */
    public function allowsPack(ReceiptPack $pack): bool
    {
        if ($this->isEmpty()) {
            return true;
        }
        if ($this->accountIds !== null && array_diff($pack->account_ids, $this->accountIds) !== []) {
            return false;
        }
        if ($this->from !== null && $pack->period_from->lessThan($this->from) || $this->to !== null && $pack->period_to->greaterThan($this->to)) {
            return false;
        }
        $ids = $pack->documentIds() ?? PackContents::load($pack->period(), $pack->account_ids)->documentIds();
        if ($ids === []) {
            return true;
        }

        return $this->documents(Document::query()->withTrashed()->whereIn('id', $ids))->count() === count($ids);
    }

    /**
     * Short pills for the Rollen page ("2026", "01.03.2026–31.05.2026", "2 Konten", "3 Ordner").
     *
     * @return list<string>
     */
    public function pills(): array
    {
        $pills = [];
        if ($this->from !== null || $this->to !== null) {
            $wholeYear = $this->from !== null && $this->to !== null && $this->from->isSameDay($this->from->startOfYear())
                && $this->to->isSameDay($this->from->endOfYear());
            $pills[] = match (true) {
                $wholeYear => (string) __('roles.only', ['what' => $this->from->format('Y')]),
                $this->to === null => (string) __('roles.since', ['date' => $this->from->format('d.m.Y')]),
                $this->from === null => (string) __('roles.until', ['date' => $this->to->format('d.m.Y')]),
                default => $this->from->format('d.m.Y').'–'.$this->to->format('d.m.Y'),
            };
        }
        if ($this->accountIds !== null) {
            $pills[] = trans_choice('roles.accounts_count', count($this->accountIds), ['count' => count($this->accountIds)]);
        }
        if ($this->folderIds !== null) {
            $pills[] = trans_choice('roles.folders_count', count($this->folderIds), ['count' => count($this->folderIds)]);
        }

        return $pills;
    }

    /** Whether the account may be seen (null id = no account). */
    public function allowsAccount(?string $id): bool
    {
        return $this->accountIds === null || ($id !== null && in_array($id, $this->accountIds, true));
    }
}
