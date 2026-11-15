<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack\Layout;

use App\Domain\Banking\Transaction;
use App\Domain\ReceiptPack\PackBooking;
use App\Domain\ReceiptPack\PackPicture;
use App\Domain\ReceiptPack\PackReceipt;
use App\Domain\ReceiptPack\PackSection;
use App\Domain\ReceiptPack\Pdf\PdfCanvas;

/**
 * One page per booking (D-018, provisional): the details on top, the first receipt picture below; every further
 * picture – all pages of a multi-page receipt, more receipts – on a page of its own. A document without a booking
 * ("Belege ohne Buchung", A38) is drawn the same way with its own short details.
 */
final class BookingPages
{
    private const float LABEL = 120.0;

    /**
     * @param  list<PackPicture>  $pictures
     */
    public static function booking(PageFrame $frame, PackSection $section, PackBooking $booking, int $number, array $pictures): void
    {
        $c = $frame->open();
        $y = PageFrame::TOP;
        $c->text(PageFrame::LEFT, $y, __('receipt_pack.pdf.booking_no', ['nr' => $number]), 9, false, PdfCanvas::MUTED);
        $c->textRight(PageFrame::RIGHT, $y + 14, PageFrame::money($booking->amountMinor, $booking->currency), 18, true, $booking->amountMinor < 0 ? PdfCanvas::BLACK : PdfCanvas::GREEN);
        $c->textFit(PageFrame::LEFT, $y + 16, $booking->counterparty !== '' ? $booking->counterparty : __('receipt_pack.pdf.no_counterparty'), 330, 15, true);
        $y += 42;
        $rows = [
            [__('receipt_pack.pdf.date'), Transaction::dateAndTime($booking->date->format('d.m.Y'), $booking->time)],
            [__('receipt_pack.pdf.amount'), PageFrame::money($booking->amountMinor, $booking->currency)],
            [__('receipt_pack.pdf.account'), $section->name.($section->maskedIban() !== '' ? '  ·  '.$section->maskedIban() : '')],
            [__('receipt_pack.pdf.purpose'), $booking->purpose !== '' ? $booking->purpose : '–'],
            [__('receipt_pack.pdf.tags'), $booking->tags !== [] ? implode(', ', $booking->tags) : '–'],
        ];
        $y = self::rows($c, $y, $rows);
        $y = self::receipts($c, $y, $booking, $pictures);
        self::firstPicture($frame, $c, $y, $pictures, __('receipt_pack.pdf.booking_no', ['nr' => $number]));
    }

    /**
     * @param  list<PackPicture>  $pictures
     */
    public static function loose(PageFrame $frame, PackReceipt $receipt, int $index, array $pictures): void
    {
        $c = $frame->open();
        $y = PageFrame::TOP;
        $c->text(PageFrame::LEFT, $y, __('receipt_pack.pdf.loose_no', ['nr' => $index]), 9, false, PdfCanvas::MUTED);
        $c->textFit(PageFrame::LEFT, $y + 16, $receipt->title, PageFrame::RIGHT - PageFrame::LEFT, 15, true);
        $y = self::rows($c, $y + 42, [
            [__('receipt_pack.pdf.receipt_date'), (string) $receipt->date],
            [__('receipt_pack.pdf.receipt'), self::pagesText($receipt->title, $pictures)],
        ]);
        self::firstPicture($frame, $c, $y + 6, $pictures, __('receipt_pack.pdf.loose_no', ['nr' => $index]));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private static function rows(PdfCanvas $c, float $y, array $rows): float
    {
        foreach ($rows as [$label, $value]) {
            $c->text(PageFrame::LEFT, $y, $label, 9, false, PdfCanvas::MUTED);
            $lines = array_slice(PdfCanvas::wrap($value, PageFrame::RIGHT - PageFrame::LEFT - self::LABEL, 9.5), 0, 3);
            foreach ($lines as $line) {
                $c->text(PageFrame::LEFT + self::LABEL, $y, $line, 9.5);
                $y += 13;
            }
            $y += 3;
        }

        return $y;
    }

    /**
     * The receipt line(s): document names with page counts, or "kein Beleg nötig" / "Beleg fehlt".
     *
     * @param  list<PackPicture>  $pictures
     */
    private static function receipts(PdfCanvas $c, float $y, PackBooking $booking, array $pictures): float
    {
        $c->text(PageFrame::LEFT, $y, __('receipt_pack.pdf.receipt'), 9, false, PdfCanvas::MUTED);
        if (! $booking->hasReceipt()) {
            $text = $booking->noReceipt ? __('receipt_pack.pdf.none_needed').($booking->noReceiptReason ? ': '.$booking->noReceiptReason : '') : __('receipt_pack.pdf.missing');
            $c->text(PageFrame::LEFT + self::LABEL, $y, $text, 9.5, ! $booking->noReceipt, $booking->noReceipt ? PdfCanvas::BLACK : PdfCanvas::RED);

            return $y + 16;
        }
        foreach ($booking->receipts as $receipt) {
            $own = array_values(array_filter($pictures, static fn (PackPicture $p): bool => $p->receiptId === $receipt->id));
            $c->textFit(PageFrame::LEFT + self::LABEL, $y, self::pagesText($receipt->title, $own), PageFrame::RIGHT - PageFrame::LEFT - self::LABEL, 9.5);
            $y += 13;
            if ($y > 300) {
                break;
            }
        }

        return $y + 3;
    }

    /**
     * @param  list<PackPicture>  $pictures
     */
    private static function pagesText(string $title, array $pictures): string
    {
        $pages = $pictures === [] ? 1 : $pictures[0]->pages;

        return $title.'  ·  '.trans_choice('receipt_pack.pdf.pages', $pages, ['count' => $pages]);
    }

    /**
     * Draws the first picture into the rest of the open page, closes it, and gives every further picture its own page.
     *
     * @param  list<PackPicture>  $pictures
     */
    private static function firstPicture(PageFrame $frame, PdfCanvas $c, float $y, array $pictures, string $head): void
    {
        $first = array_shift($pictures);
        if ($first !== null) {
            $c->line(PageFrame::LEFT, $y + 2, PageFrame::RIGHT, $y + 2);
            self::picture($frame, $c, $first, $y + 12);
        }
        $frame->close($c);
        foreach ($pictures as $picture) {
            $c = $frame->open();
            $c->text(PageFrame::LEFT, PageFrame::TOP, $head.' · '.__('receipt_pack.pdf.continued', ['title' => $picture->title, 'page' => $picture->page, 'pages' => $picture->pages]), 9, false, PdfCanvas::MUTED);
            self::picture($frame, $c, $picture, PageFrame::TOP + 14);
            $frame->close($c);
        }
    }

    private static function picture(PageFrame $frame, PdfCanvas $c, PackPicture $picture, float $top): void
    {
        $width = PageFrame::RIGHT - PageFrame::LEFT;
        $height = PageFrame::BOTTOM - $top;
        $bytes = $picture->jpeg !== null ? @file_get_contents($picture->jpeg) : false;
        if (is_string($bytes) && $bytes !== '') {
            try {
                $c->image($frame->writer->jpeg($bytes), PageFrame::LEFT, $top, $width, $height);
                @unlink((string) $picture->jpeg);

                return;
            } catch (\RuntimeException) {
                // falls through to the note
            }
        }
        $c->box(PageFrame::LEFT, $top, $width, 90);
        $c->text(PageFrame::LEFT + 16, $top + 34, __('receipt_pack.pdf.note.'.($picture->note ?? 'other')), 11, true);
        $c->textFit(PageFrame::LEFT + 16, $top + 54, $picture->title, $width - 32, 9.5, false, PdfCanvas::MUTED);
        $c->text(PageFrame::LEFT + 16, $top + 72, __('receipt_pack.pdf.note_original'), 9, false, PdfCanvas::MUTED);
    }
}
