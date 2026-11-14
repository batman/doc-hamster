<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack\Layout;

use App\Domain\ReceiptPack\PackContents;
use App\Domain\ReceiptPack\PackFigures;
use App\Domain\ReceiptPack\Pdf\PdfCanvas;
use Carbon\CarbonImmutable;

/**
 * Page 1 of a receipt pack (D-018, provisional): organisation, period, created, version, the accounts with masked
 * IBAN, the key figures in total and per account – missing receipts in red.
 */
final class CoverPage
{
    /**
     * @param  list<string>  $notShown
     */
    public static function draw(PageFrame $frame, PackContents $contents, CarbonImmutable $created, array $notShown): void
    {
        $c = $frame->open(false);
        $left = PageFrame::LEFT;
        $right = PageFrame::RIGHT;
        $c->box(0, 0, 595, 6, [1.0, 0.46, 0.13]);
        $c->text($left, 92, __('receipt_pack.pdf.title'), 28, true);
        $c->textFit($left, 122, $frame->org, $right - $left, 15);
        $y = 158;
        foreach ([
            [__('receipt_pack.pdf.period'), $contents->period->human().'  ('.$frame->label.')'],
            [__('receipt_pack.pdf.created'), $created->format('d.m.Y H:i')],
            [__('receipt_pack.pdf.version'), 'v'.$frame->version],
        ] as [$label, $value]) {
            $c->text($left, $y, $label, 10, false, PdfCanvas::MUTED);
            $c->text($left + 110, $y, $value, 10);
            $y += 16;
        }

        $total = $contents->figures();
        $y += 18;
        $c->text($left, $y, __('receipt_pack.pdf.figures'), 12, true);
        $y += 10;
        $c->box($left, $y, $right - $left, 96);
        $y = self::figures($c, $total, $y + 22, $left + 14, $right - 14);

        $y += 30;
        $c->text($left, $y, __('receipt_pack.pdf.accounts'), 12, true);
        $y += 20;
        $cols = [$left, $left + 190, $left + 300, $left + 350, $right - 130, $right - 50, $right];
        foreach (['account', 'iban', 'bookings', 'with', 'expenses', 'missing'] as $i => $key) {
            $c->text($cols[$i], $y, __('receipt_pack.pdf.col.'.$key), 8, true, PdfCanvas::MUTED);
        }
        $y += 5;
        $c->line($left, $y, $right, $y);
        foreach ($contents->sections as $section) {
            $y += 15;
            $f = $section->figures();
            $c->textFit($cols[0], $y, $section->name, 185, 9.5, true);
            $c->textFit($cols[1], $y, $section->kind === 'cash' ? __('receipt_pack.pdf.cash') : ($section->maskedIban() ?: '–'), 105, 8.5);
            $c->text($cols[2], $y, (string) $f->count, 9.5);
            $c->text($cols[3], $y, (string) $f->withReceipt, 9.5);
            $c->textRight($cols[5] - 12, $y, PageFrame::sums($f->expenses, $section->currency), 9);
            $c->textRight($right, $y, (string) $f->missingCount(), 9.5, $f->missingCount() > 0, $f->missingCount() > 0 ? PdfCanvas::RED : PdfCanvas::BLACK);
            if ($y > 700) {
                break;
            }
        }
        if ($contents->loose !== []) {
            $y += 26;
            $c->text($left, $y, __('receipt_pack.pdf.loose_count', ['count' => count($contents->loose)]), 9.5);
        }
        if ($notShown !== []) {
            $y += 16;
            $c->text($left, $y, __('receipt_pack.pdf.not_shown_count', ['count' => count($notShown)]), 9.5, false, PdfCanvas::MUTED);
        }
        $frame->close($c);
    }

    /** The key figures in two columns; returns the y below them. */
    private static function figures(PdfCanvas $c, PackFigures $f, float $y, float $left, float $right): float
    {
        $mid = $left + ($right - $left) / 2;
        $rows = [
            [[__('receipt_pack.pdf.fig.bookings'), (string) $f->count, false], [__('receipt_pack.pdf.fig.expenses'), PageFrame::sums($f->expenses), false]],
            [[__('receipt_pack.pdf.fig.with'), (string) $f->withReceipt, false], [__('receipt_pack.pdf.fig.income'), PageFrame::sums($f->income), false]],
            [[__('receipt_pack.pdf.fig.none'), (string) $f->noReceipt, false], [__('receipt_pack.pdf.fig.missing'), (string) $f->missingCount(), $f->missingCount() > 0]],
        ];
        foreach ($rows as $row) {
            foreach ($row as $i => [$label, $value, $alert]) {
                $x = $i === 0 ? $left : $mid + 10;
                $end = $i === 0 ? $mid - 10 : $right;
                $c->text($x, $y, $label, 10, false, PdfCanvas::MUTED);
                $c->textRight($end, $y, $value, 11, true, $alert ? PdfCanvas::RED : PdfCanvas::BLACK);
            }
            $y += 24;
        }

        return $y;
    }
}
