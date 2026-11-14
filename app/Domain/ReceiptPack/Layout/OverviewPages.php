<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack\Layout;

use App\Domain\ReceiptPack\PackContents;
use App\Domain\ReceiptPack\PackPlan;
use App\Domain\ReceiptPack\Pdf\PdfCanvas;

/**
 * The overview (D-018, provisional): per account section Nr · Datum · Empfänger/Gegenpartei · Betrag · Beleg ✓/✗ ·
 * Seite. Which rows stand on which page is decided by PackPlan; this only draws them.
 */
final class OverviewPages
{
    private const float ROW = 17.0;

    /** x of the columns: Nr (right edge), Datum, Gegenpartei, Betrag (right edge), Beleg (centre), Seite (right edge). */
    private const array X = [62.0, 72.0, 128.0, 470.0, 497.0, 553.0];

    public static function draw(PageFrame $frame, PackContents $contents, PackPlan $plan): void
    {
        foreach ($plan->overview as $index => $rows) {
            $c = $frame->open();
            $y = PageFrame::TOP;
            if ($index === 0) {
                $c->text(PageFrame::LEFT, $y, __('receipt_pack.pdf.overview'), 16, true);
            }
            $y += 10;
            foreach ($rows as $row) {
                $section = $contents->sections[$row[1]];
                if ($row[0] === 'head') {
                    $y += self::ROW + 4;
                    $c->text(PageFrame::LEFT, $y, $section->name, 11, true);
                    $iban = $section->kind === 'cash' ? __('receipt_pack.pdf.cash') : $section->maskedIban();
                    $c->textRight(PageFrame::RIGHT, $y, $iban, 8.5, false, PdfCanvas::MUTED);
                    $y += self::ROW - 2;
                    self::header($c, $y);
                    $y += 4;
                    $c->line(PageFrame::LEFT, $y, PageFrame::RIGHT, $y);

                    continue;
                }
                $b = $row[2] ?? 0;
                $booking = $section->bookings[$b];
                $y += self::ROW;
                $c->textRight(self::X[0], $y, (string) $plan->number[$row[1]][$b], 9, false, PdfCanvas::MUTED);
                $c->text(self::X[1], $y, $booking->date->format('d.m.Y'), 9);
                $c->textFit(self::X[2], $y, $booking->counterparty !== '' ? $booking->counterparty : $booking->purpose, self::X[3] - self::X[2] - 90, 9);
                $c->textRight(self::X[3], $y, PageFrame::money($booking->amountMinor, $booking->currency), 9, false, $booking->amountMinor < 0 ? PdfCanvas::BLACK : PdfCanvas::GREEN);
                if ($booking->noReceipt && ! $booking->hasReceipt()) {
                    $c->text(self::X[4] - 3, $y, "\u{2013}", 9, false, PdfCanvas::MUTED);
                } else {
                    $c->mark(self::X[4], $y, $booking->hasReceipt(), 9);
                }
                $c->textRight(self::X[5], $y, (string) $plan->bookingPage[$row[1]][$b], 9);
                $c->line(PageFrame::LEFT, $y + 5, PageFrame::RIGHT, $y + 5, 0.3);
            }
            if ($contents->sections === []) {
                $c->text(PageFrame::LEFT, $y + 30, __('receipt_pack.pdf.no_bookings'), 10, false, PdfCanvas::MUTED);
            }
            $frame->close($c);
        }
    }

    private static function header(PdfCanvas $c, float $y): void
    {
        $c->textRight(self::X[0], $y, __('receipt_pack.pdf.col.nr'), 7.5, true, PdfCanvas::MUTED);
        $c->text(self::X[1], $y, __('receipt_pack.pdf.col.date'), 7.5, true, PdfCanvas::MUTED);
        $c->text(self::X[2], $y, __('receipt_pack.pdf.col.counterparty'), 7.5, true, PdfCanvas::MUTED);
        $c->textRight(self::X[3], $y, __('receipt_pack.pdf.col.amount'), 7.5, true, PdfCanvas::MUTED);
        $c->textRight(self::X[4] + 10, $y, __('receipt_pack.pdf.col.receipt'), 7.5, true, PdfCanvas::MUTED);
        $c->textRight(self::X[5], $y, __('receipt_pack.pdf.col.page'), 7.5, true, PdfCanvas::MUTED);
    }
}
