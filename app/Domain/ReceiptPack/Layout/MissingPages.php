<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack\Layout;

use App\Domain\ReceiptPack\PackContents;
use App\Domain\ReceiptPack\PackPlan;
use App\Domain\ReceiptPack\Pdf\PdfCanvas;

/**
 * The end of a receipt pack (D-018, provisional): every booking whose receipt is missing (Nr · Datum · Konto ·
 * Gegenpartei · Betrag · Seite), then the receipts that are in the pack only as a note (their original files lie in
 * the tax advisor's access). Rows per page: PackPlan::MISSING_ROWS.
 */
final class MissingPages
{
    private const float ROW = 16.0;

    /**
     * Rows of the list: each a missing booking or a not shown receipt (with the headings they need).
     *
     * @param  list<string>  $notShown
     * @return list<array{0: string, 1?: int, 2?: int, 3?: string}>
     */
    public static function rows(PackContents $contents, array $notShown): array
    {
        $rows = [];
        foreach ($contents->sections as $s => $section) {
            foreach ($section->bookings as $b => $booking) {
                if ($booking->isMissing()) {
                    $rows[] = ['missing', $s, $b];
                }
            }
        }
        if ($notShown !== []) {
            $rows[] = ['gap'];
            $rows[] = ['title'];
            foreach ($notShown as $title) {
                $rows[] = ['file', 0, 0, $title];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{0: string, 1?: int, 2?: int, 3?: string}>  $rows
     */
    public static function draw(PageFrame $frame, PackContents $contents, PackPlan $plan, array $rows): void
    {
        $chunks = $rows === [] ? [[]] : array_chunk($rows, PackPlan::MISSING_ROWS);
        foreach ($chunks as $index => $chunk) {
            $c = $frame->open();
            $y = PageFrame::TOP;
            if ($index === 0) {
                $c->text(PageFrame::LEFT, $y, __('receipt_pack.pdf.missing_title'), 16, true);
                $y += 10;
                if ($rows === [] || $rows[0][0] !== 'missing') {
                    $y += self::ROW;
                    $c->text(PageFrame::LEFT, $y, __('receipt_pack.pdf.missing_none'), 10, false, PdfCanvas::GREEN);
                }
            }
            foreach ($chunk as $row) {
                $y += self::ROW;
                match ($row[0]) {
                    'missing' => self::booking($c, $y, $contents, $plan, $row[1] ?? 0, $row[2] ?? 0),
                    'title' => $c->text(PageFrame::LEFT, $y, __('receipt_pack.pdf.not_shown_title'), 11, true),
                    'file' => $c->textFit(PageFrame::LEFT, $y, ($row[3] ?? '').' – '.__('receipt_pack.pdf.note_original'), PageFrame::RIGHT - PageFrame::LEFT, 9),
                    default => null,
                };
            }
            $frame->close($c);
        }
    }

    private static function booking(PdfCanvas $c, float $y, PackContents $contents, PackPlan $plan, int $s, int $b): void
    {
        $section = $contents->sections[$s];
        $booking = $section->bookings[$b];
        $c->textRight(62, $y, (string) $plan->number[$s][$b], 9, false, PdfCanvas::MUTED);
        $c->text(72, $y, $booking->date->format('d.m.Y'), 9);
        $c->textFit(128, $y, $section->name, 110, 9);
        $c->textFit(245, $y, $booking->counterparty !== '' ? $booking->counterparty : $booking->purpose, 170, 9);
        $c->textRight(510, $y, PageFrame::money($booking->amountMinor, $booking->currency), 9, false, PdfCanvas::RED);
        $c->textRight(PageFrame::RIGHT, $y, (string) $plan->bookingPage[$s][$b], 9);
    }
}
