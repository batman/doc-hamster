<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack\Layout;

use App\Domain\Banking\Transaction;
use App\Domain\ReceiptPack\Pdf\PdfCanvas;
use App\Domain\ReceiptPack\Pdf\PdfWriter;

/**
 * What every page of a receipt pack shares (D-018, provisional – change the look here): the thin head line with
 * pack and organisation, and the footer "Seite n von m · v3 · Zusammenstellung aus docHamster – kein steuerliches
 * Dokument". Pages are counted as they are written, so the plan and the drawing must agree.
 */
final class PageFrame
{
    public const float LEFT = 42.0;

    public const float RIGHT = 553.0;

    public const float TOP = 64.0;

    /** Lowest y the content may use (the footer sits below). */
    public const float BOTTOM = 790.0;

    private int $page = 0;

    public function __construct(
        public readonly PdfWriter $writer,
        public readonly int $pages,
        public readonly string $org,
        public readonly string $label,
        public readonly int $version,
    ) {}

    /** A new canvas with head and footer for the next page. */
    public function open(bool $head = true): PdfCanvas
    {
        $this->page++;
        $canvas = new PdfCanvas;
        if ($head) {
            $canvas->textFit(self::LEFT, 34, __('receipt_pack.pdf.head', ['period' => $this->label, 'org' => $this->org]), self::RIGHT - self::LEFT, 7.5, false, PdfCanvas::MUTED);
            $canvas->line(self::LEFT, 40, self::RIGHT, 40);
        }
        $canvas->line(self::LEFT, 803, self::RIGHT, 803);
        $canvas->text(self::LEFT, 815, __('receipt_pack.pdf.page', ['page' => $this->page, 'pages' => $this->pages]), 7.5, false, PdfCanvas::MUTED);
        $note = __('receipt_pack.pdf.footer', ['version' => $this->version]);
        $canvas->textRight(self::RIGHT, 815, $note, 7.5, false, PdfCanvas::MUTED);

        return $canvas;
    }

    public function close(PdfCanvas $canvas): void
    {
        $this->writer->page($canvas);
    }

    public function current(): int
    {
        return $this->page;
    }

    /** "-12,30 EUR" / "+12,30 EUR". */
    public static function money(int $minor, string $currency): string
    {
        return Transaction::signed($minor, $currency);
    }

    /**
     * "1.234,00 EUR · 12,00 USD" for the per-currency sums; "0,00 EUR" when empty.
     *
     * @param  array<string, int>  $sums
     */
    public static function sums(array $sums, string $fallback = 'EUR'): string
    {
        if ($sums === []) {
            return Transaction::money(0, $fallback);
        }
        $parts = [];
        foreach ($sums as $currency => $minor) {
            $parts[] = Transaction::money($minor, $currency);
        }

        return implode(' · ', $parts);
    }
}
