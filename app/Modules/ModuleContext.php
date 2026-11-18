<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Queue\QueueName;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The door of one module into docHamster (D-041, docs/modules/BUILDING.md) – handed to Module::register().
 *
 * Interface (F17 a: only adding): routes() under /m/<key>, menuEntry(), settingsPage(), documentAction(),
 * bookingAction(). Data and events only with the rights the owner granted: documents() / bookings() to read, write()
 * to change, on() for events, netJob() for the internet (only through the net queue, D-020). Views, translations and
 * migrations of the module are found by themselves: views `m-<key>::…`, texts `m-<key>::file.key`.
 *
 * The rights make plain what a module wants and keep honest modules in their lane; a module is still PHP code on
 * this server – only install what you trust (D-041).
 */
final class ModuleContext
{
    /** @param list<string> $rights */
    public function __construct(
        private readonly Manifest $manifest,
        private readonly array $rights,
        private readonly ModuleConnections $connections,
    ) {}

    public function key(): string
    {
        return $this->manifest->key;
    }

    public function allows(string $right): bool
    {
        return in_array($right, $this->rights, true);
    }

    /** The module's own pages: /m/<key>/…, route names "m.<key>.…", for signed-in people of this installation. */
    public function routes(Closure $define): void
    {
        Route::middleware(['web', 'auth', 'org'])->prefix('m/'.$this->key())->name('m.'.$this->key().'.')->group($define);
        // Modules start after Laravel indexed the route names – index again, so route('m.<key>.…') works.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /** A main menu entry (opens the route as a window). */
    public function menuEntry(string $label, string $icon, string $route): void
    {
        $this->connections->menu[] = ['module' => $this->key(), 'label' => $label, 'icon' => self::icon($icon), 'route' => $this->route($route)];
    }

    /** A page under Einstellungen → Module (for the owner). */
    public function settingsPage(string $title, string $text, string $icon, string $route): void
    {
        $this->connections->settings[] = ['module' => $this->key(), 'title' => $title, 'text' => $text, 'icon' => self::icon($icon), 'route' => $this->route($route)];
    }

    /**
     * An entry in a document's ⋯ menu; the route gets the document id as {id} and opens as a window. $when (optional) is
     * asked when the menu is drawn – e.g. only while a setting of the module is on.
     */
    public function documentAction(string $label, string $icon, string $route, ?callable $when = null): void
    {
        $this->connections->documentActions[] = ['module' => $this->key(), 'label' => $label, 'icon' => self::icon($icon), 'route' => $this->route($route), 'when' => $when];
    }

    /** An entry in a booking's ⋯ menu; the route gets the booking id as {id}. */
    public function bookingAction(string $label, string $icon, string $route): void
    {
        $this->connections->bookingActions[] = ['module' => $this->key(), 'label' => $label, 'icon' => self::icon($icon), 'route' => $this->route($route)];
    }

    /** Listen to a core event (ModuleApi::EVENTS); the event's right must be granted. */
    public function on(string $event, callable $listener): void
    {
        if (! in_array($event, ModuleApi::EVENTS, true)) {
            throw new ModuleRightMissing('Unknown event '.$event);
        }
        $this->need(ModuleApi::EVENT_RIGHT[$event]);
        $this->connections->listen($event, $this->key(), $listener);
    }

    /**
     * Documents to read – never those in private folders (A69).
     *
     * @return Builder<Document>
     */
    public function documents(): Builder
    {
        $this->need('documents_read');

        return Document::query()->notPrivate();
    }

    /** @return Builder<Transaction> */
    public function bookings(): Builder
    {
        $this->need('bookings_read');

        return Transaction::query();
    }

    /**
     * Changes documents or bookings in one transaction; $what = "documents" or "bookings".
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    public function write(string $what, Closure $change): mixed
    {
        $this->need($what === 'bookings' ? 'bookings_write' : 'documents_write');

        return DB::transaction($change);
    }

    /**
     * A97: the bank account this module keeps its bookings in ("<key>:<id>" is the module's own key for it), found or made.
     */
    public function bankAccount(string $id, string $name, string $currency, ?string $iban = null): BankAccount
    {
        $this->need('bookings_write');

        return ModuleBanking::account($this->key().':'.$id, $name, $currency, $iban);
    }

    /** A97: ties an account the person chose (e.g. filled by CSV before) to this module's account id. */
    public function adoptBankAccount(BankAccount $account, string $id): BankAccount
    {
        $this->need('bookings_write');

        return ModuleBanking::adopt($account, $this->key().':'.$id);
    }

    /** The account this module already keeps under its id, or null. */
    public function findBankAccount(string $id): ?BankAccount
    {
        $this->need('bookings_read');

        return BankAccount::query()->where('source_key', $this->key().':'.$id)->first();
    }

    /**
     * A98: what importing these lines would do – nothing is stored.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{line: array<string, mixed>, action: string}>
     */
    public function planBookings(?BankAccount $account, array $lines): array
    {
        $this->need('bookings_read');

        return ModuleBanking::plan($account, $lines);
    }

    /**
     * A97: stores bookings like a statement import (recognised by bank ID, pending ones completed) and runs the matching.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{new: int, updated: int, suggested: int}
     */
    public function importBookings(BankAccount $account, array $lines): array
    {
        $this->need('bookings_write');

        return ModuleBanking::import($account, $lines, $this->key());
    }

    /** F23: a receipt the bank keeps becomes a document linked to the booking (once; a known file is only linked). */
    public function attachReceipt(Transaction $booking, string $content, string $filename, string $title): Document
    {
        $this->need('documents_write');
        $this->need('bookings_write');

        return ModuleBanking::receipt($booking, $content, $filename, $title);
    }

    /**
     * Runs $task every $minutes (at least 5) as the owner, from the cron of docHamster (D-010); work with the internet
     * belongs into netJob() inside the task.
     */
    public function every(int $minutes, callable $task): void
    {
        $this->connections->tasks[] = ['module' => $this->key(), 'minutes' => max(5, $minutes), 'task' => $task];
    }

    /** Work that talks to the internet runs only as a job on the net queue (D-020) and only with the right "network". */
    public function netJob(object $job): void
    {
        $this->need('network');
        dispatch($job)->onQueue(QueueName::Net->value);
    }

    private function need(string $right): void
    {
        if (! $this->allows($right)) {
            throw new ModuleRightMissing(__('modules.right_missing', ['right' => __('modules.rights.'.$right)]));
        }
    }

    private function route(string $name): string
    {
        return str_starts_with($name, 'm.'.$this->key().'.') ? $name : 'm.'.$this->key().'.'.$name;
    }

    /** Only symbols docHamster has; anything else becomes the puzzle piece. */
    private static function icon(string $name): string
    {
        return preg_match('/^[a-z0-9-]+$/', $name) === 1 && is_file(resource_path('icons/tabler/'.$name.'.svg')) ? $name : 'puzzle';
    }
}
