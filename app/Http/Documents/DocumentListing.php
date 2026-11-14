<?php

declare(strict_types=1);

namespace App\Http\Documents;

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Organization;
use App\Domain\Org\Permission;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertySearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Everything a documents window shows (documents/index.blade.php): its folder rows and documents in the window's view,
 * narrowed by the search (DocumentSearch), sorted, and the calendar month.
 */
final class DocumentListing
{
    /** The views of the list, like the Windows Explorer (ui-redesign point 10), with their icon. */
    public const VIEWS = [
        'details' => 'list-details',
        'list' => 'list',
        'small' => 'layout-grid',
        'large' => 'layout-cards',
        'timeline' => 'timeline',
        'calendar' => 'calendar',
    ];

    /** The display modes of the folder view (point 98); timeline and calendar are main views of their own. */
    public const FOLDER_VIEWS = ['details', 'list', 'small', 'large'];

    /** The date timeline and calendar go by (point 108): receipt date (falls back to the upload) or upload date. */
    public const DATE_BASES = ['doc', 'upload'];

    private const LIMIT = 200;

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request, User $user): array
    {
        $seesAll = Gate::allows(Permission::ViewAllDocuments->value);
        $query = Document::query()->visibleTo($user->id, $seesAll)->with(['files', 'tags']);

        $search = DocumentSearch::read($request);
        $search->apply($query);
        $properties = $search->properties;

        // Point 105: a search shows a result list of documents only (no folders), each with its folder (A204).
        $searching = $search->searching();
        if ($searching) {
            $query->with('folder');
        }

        // Like an explorer (points 10-12): a folder shows only its own receipts, the top level only those
        // without a folder; a search looks through everything below the current folder.
        $folders = Folder::flat();
        $wantedFolder = $request->query('folder');
        $folder = is_string($wantedFolder) && collect($folders)->contains(static fn (array $row): bool => $row['folder']->id === $wantedFolder) ? $wantedFolder : null;
        // The view belongs to the window (point 52): ?view=… in its address; a window without one starts with the
        // view the person chose last (receipt_view).
        $wantedView = $request->query('view');
        $saved = $user->receipt_view;
        $view = is_string($wantedView) && isset(self::VIEWS[$wantedView]) ? $wantedView
            : ($saved !== null && isset(self::VIEWS[$saved]) ? $saved : 'details');
        // Point 98: three main views – the folder view (in its last display mode), timeline and calendar.
        $folderMode = in_array($view, self::FOLDER_VIEWS, true) ? $view
            : (in_array($user->folder_view, self::FOLDER_VIEWS, true) ? $user->folder_view : 'details');
        // The results of a search show in the folder view's display mode, also in a timeline or calendar window;
        // closing the search brings the window's own view back (?view= stays).
        if ($searching) {
            $view = $folderMode;
        }
        $wide = ! in_array($view, self::FOLDER_VIEWS, true);
        $wantedDates = $request->query('date');
        $dates = is_string($wantedDates) && in_array($wantedDates, self::DATE_BASES, true) ? $wantedDates
            : (in_array($user->receipt_dates, self::DATE_BASES, true) ? $user->receipt_dates : 'doc');

        // Timeline and calendar show every document of every folder (points 100, 101; the trash never, A201).
        // A search and the tag filter look through everything below the current folder.
        if (! $wide && $searching) {
            if ($folder !== null && ! $search->all) {
                $query->whereIn('folder_id', Folder::branchIds($folder));
            }
        } elseif (! $wide && $folder !== null) {
            $query->where('folder_id', $folder);
        } elseif (! $wide) {
            $query->whereNull('folder_id');
        }

        // A244: the details view sorts by a click on a column head (?sort=name|date|prop:<id>&dir=asc|desc) and shows the
        // property columns this person chose (users.document_columns).
        $wantedSort = $request->query('sort');
        $sortProperty = is_string($wantedSort) && str_starts_with($wantedSort, 'prop:') ? $properties->firstWhere('id', substr($wantedSort, 5)) : null;
        $sort = $sortProperty instanceof Property || in_array($wantedSort, ['name', 'date'], true) ? (string) $wantedSort : null;
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $chosen = is_array($user->document_columns) ? $user->document_columns : [];
        $columns = $view === 'details' ? $properties->filter(static fn (Property $p): bool => in_array($p->id, $chosen, true))->values() : collect();

        $month = null;
        $range = null;
        $dateSql = Document::dateSql($dates);
        if ($view === 'calendar') {
            // Point 107: only the months from the earliest to the latest document; by default the latest one,
            // or this month when it lies inside.
            $bounds = $query->clone()->toBase()->selectRaw("MIN($dateSql) as lo, MAX($dateSql) as hi")->first();
            $lo = is_object($bounds) && is_string($bounds->lo ?? null) ? CarbonImmutable::parse($bounds->lo)->startOfMonth() : null;
            $hi = is_object($bounds) && is_string($bounds->hi ?? null) ? CarbonImmutable::parse($bounds->hi)->startOfMonth() : null;
            $now = CarbonImmutable::now()->startOfMonth();
            $wanted = $request->query('month');
            $parsed = is_string($wanted) && preg_match('/^\d{4}-\d{2}$/', $wanted) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $wanted) : null;
            if ($lo !== null && $hi !== null) {
                $range = ['first' => $lo, 'last' => $hi];
                $month = $parsed instanceof CarbonImmutable ? $parsed : ($now->between($lo, $hi) ? $now : $hi);
                $month = $month->lessThan($lo) ? $lo : ($month->greaterThan($hi) ? $hi : $month);
            } else {
                $month = $now;
            }
            $documents = $query->whereRaw("$dateSql between ? and ?", [$month->toDateString(), $month->endOfMonth()->toDateString()])
                ->orderByRaw("$dateSql asc")->oldest()->get();
        } elseif ($view === 'timeline') {
            $documents = $query->orderByRaw("$dateSql desc")->latest()->limit(self::LIMIT + 1)->get();
        } else {
            $this->sortQuery($query, $sort, $dir, $sortProperty);
            $documents = $query->limit(self::LIMIT + 1)->get();
        }
        if ($columns->isNotEmpty()) {
            $documents->load('properties');
        }
        // Filtering in the browser works only when the page holds every receipt (point 7).
        $complete = ! $searching && ($view === 'calendar' || $documents->count() <= self::LIMIT);
        $organization = $this->organization();

        return [
            ...$search->viewData(),
            'open' => null,
            'documents' => $view === 'calendar' ? $documents : $documents->take(self::LIMIT),
            'folders' => $folders,
            'folder' => $folder,
            'folderRows' => $wide || $searching ? [] : $this->folderRows($user, $seesAll, $folders, $folder),
            'columns' => $columns,
            'chosenColumns' => $chosen,
            'sort' => $sort,
            'dir' => $dir,
            'view' => $view,
            'views' => self::VIEWS,
            'folderViews' => self::FOLDER_VIEWS,
            'folderMode' => $folderMode,
            'wide' => $wide,
            'dates' => $dates,
            'range' => $range,
            'month' => $month,
            'complete' => $complete,
            'trashed' => Gate::allows(Permission::TrashDocuments->value) ? Document::query()->visibleTo($user->id, $seesAll)->withPrintTray()->onlyTrashed()->count() : 0,
            'tree' => Folder::tree($folders),
            'trashName' => Folder::trashName($organization),
            'trashColor' => Folder::trashColor($organization),
        ];
    }

    /**
     * The order of the folder views: newest first, or by the clicked column head (A244).
     *
     * @param  Builder<Document>  $query
     * @param  'asc'|'desc'  $dir
     */
    private function sortQuery(Builder $query, ?string $sort, string $dir, ?Property $property): void
    {
        match (true) {
            $property !== null => PropertySearch::orderBy($query, $property, $dir),
            $sort === 'name' => $query->orderBy('title', $dir),
            $sort === 'date' => $query->orderBy('created_at', $dir),
            default => $query->latest(),
        };
        if ($sort !== null) {
            $query->orderBy('id');
        }
    }

    /**
     * The folder rows on top of a Belege window (window system, PO 2026-11-07): without a folder the
     * top folders with the Papierkorb in its place, inside a folder its subfolders. Each row counts its subfolders, its
     * own documents and all documents below it (point 161) – one grouped query for all folders, only documents this
     * person may see (the trash is never counted, it shows its own count).
     *
     * @param  list<array{folder: Folder, depth: int}>  $folders
     * @return list<array{folder: Folder|null, folders: int, documents: int, total: int}>
     */
    private function folderRows(User $user, bool $seesAll, array $folders, ?string $folder): array
    {
        $own = Document::query()->visibleTo($user->id, $seesAll)
            ->whereNotNull('folder_id')->selectRaw('folder_id, count(*) as total')->groupBy('folder_id')->pluck('total', 'folder_id')
            ->map(static fn (mixed $n): int => is_numeric($n) ? (int) $n : 0)->all();
        $counts = Folder::counts($folders, $own);
        $row = static fn (Folder $f): array => ['folder' => $f, ...($counts[$f->id] ?? ['folders' => 0, 'documents' => 0, 'total' => 0])];

        $out = [];
        if ($folder !== null) {
            foreach ($folders as $entry) {
                if ($entry['folder']->parent_id === $folder) {
                    $out[] = $row($entry['folder']);
                }
            }

            return $out;
        }

        foreach (Folder::menu($this->organization()) as $entry) {
            if ($entry['depth'] !== 0) {
                continue;
            }
            if ($entry['folder'] === null) {
                // The Papierkorb, in its place among the top folders, for people who may open it.
                if (Gate::allows(Permission::TrashDocuments->value)) {
                    $out[] = ['folder' => null, 'folders' => 0, 'documents' => 0, 'total' => 0];
                }

                continue;
            }
            $out[] = $row($entry['folder']);
        }

        return $out;
    }

    private function organization(): ?Organization
    {
        $org = app(CurrentOrg::class);

        return $org->isSet() ? $org->organization() : null;
    }
}
