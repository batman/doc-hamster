<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

/**
 * Where everything of a receipt pack lands, before anything is drawn (point 211.1) – the overview needs the page of
 * every booking, the footer the page count:
 *
 *   1 cover · overview (rows of all sections, a section head where an account starts or goes on) · one page per
 *   booking, plus one per further receipt picture · "Belege ohne Buchung" the same way · the missing list.
 *
 * Input: per section the picture count of each booking, the picture count of each document without booking, and how
 * many receipts are missing. Pictures include placeholder pages ("Beleg ist ein PDF …").
 */
final class PackPlan
{
    /** Rows on one overview page (a section head takes HEAD_ROWS of them). */
    public const int OVERVIEW_ROWS = 40;

    public const int HEAD_ROWS = 3;

    /** Rows of the missing list per page. */
    public const int MISSING_ROWS = 42;

    /** @var list<list<array{0: string, 1: int, 2?: int}>> per overview page: ['head', section] or ['row', section, booking] */
    public array $overview = [];

    /** @var list<list<int>> section => booking => first page */
    public array $bookingPage = [];

    /** @var list<list<int>> section => booking => running number "Nr" (1, 2, … across all sections) */
    public array $number = [];

    /** @var list<int> document without booking => first page */
    public array $loosePage = [];

    public int $missingPage = 0;

    public int $missingPages = 1;

    public int $pages = 0;

    /**
     * @param  list<list<int>>  $sections  section => booking => pictures (0 = no receipt)
     * @param  list<int>  $loose  document without booking => pictures
     */
    public function __construct(array $sections, array $loose, int $missing)
    {
        $this->planOverview($sections);
        $next = 2 + count($this->overview);
        $nr = 1;
        foreach ($sections as $s => $bookings) {
            $this->bookingPage[$s] = [];
            $this->number[$s] = [];
            foreach ($bookings as $pictures) {
                $this->bookingPage[$s][] = $next;
                $this->number[$s][] = $nr++;
                $next += max(1, $pictures);
            }
        }
        foreach ($loose as $pictures) {
            $this->loosePage[] = $next;
            $next += max(1, $pictures);
        }
        $this->missingPage = $next;
        $this->missingPages = max(1, (int) ceil($missing / self::MISSING_ROWS));
        $this->pages = $next + $this->missingPages - 1;
    }

    /** The first page of the overview (always 2: the cover is page 1). */
    public function overviewPage(): int
    {
        return 2;
    }

    /**
     * @param  list<list<int>>  $sections
     */
    private function planOverview(array $sections): void
    {
        $page = [];
        $used = 0;
        foreach ($sections as $s => $bookings) {
            // A head never stands alone at the foot of a page.
            if ($page !== [] && $used + self::HEAD_ROWS + 1 > self::OVERVIEW_ROWS) {
                $this->overview[] = $page;
                [$page, $used] = [[], 0];
            }
            $page[] = ['head', $s];
            $used += self::HEAD_ROWS;
            foreach (array_keys($bookings) as $b) {
                if ($used + 1 > self::OVERVIEW_ROWS) {
                    $this->overview[] = $page;
                    // The account's head again on the next page.
                    [$page, $used] = [[['head', $s]], self::HEAD_ROWS];
                }
                $page[] = ['row', $s, $b];
                $used++;
            }
        }
        $this->overview[] = $page;
    }
}
