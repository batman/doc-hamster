<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

use App\Domain\Org\Organization;
use App\Domain\ReceiptPack\Layout\BookingPages;
use App\Domain\ReceiptPack\Layout\CoverPage;
use App\Domain\ReceiptPack\Layout\MissingPages;
use App\Domain\ReceiptPack\Layout\OverviewPages;
use App\Domain\ReceiptPack\Layout\PageFrame;
use App\Domain\ReceiptPack\Pdf\PdfWriter;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Builds and freezes one receipt pack (point 211): reads the contents, turns every receipt into pictures, plans the
 * pages (the overview needs every booking's page), then writes cover, overview, booking pages, "Belege ohne Buchung"
 * and the missing list into private storage. Needs the pack's organisation as the active one.
 */
final class PackBuilder
{
    /** Up to this many bookings and documents a pack is built at once; more go to the background job. */
    public const int DIRECT_LIMIT = 40;

    /**
     * @param  Closure(int, int): void|null  $progress  done, total
     */
    public static function build(ReceiptPack $pack, ?Closure $progress = null): void
    {
        $contents = PackContents::load($pack->period(), $pack->account_ids);
        $work = storage_path('app/receipt-packs-tmp/'.$pack->id);
        File::ensureDirectoryExists($work);
        try {
            $images = new PackImages($work);
            $total = max(1, count($contents->loose) + array_sum(array_map(static fn (PackSection $s): int => count($s->bookings), $contents->sections)));
            $done = 0;
            $pictures = [];
            foreach ($contents->sections as $s => $section) {
                foreach ($section->bookings as $b => $booking) {
                    $pictures[$s][$b] = [];
                    foreach ($booking->receipts as $receipt) {
                        array_push($pictures[$s][$b], ...$images->of($receipt));
                    }
                    $progress?->__invoke(++$done, $total);
                }
            }
            $loose = [];
            foreach ($contents->loose as $l => $receipt) {
                $loose[$l] = $images->of($receipt);
                $progress?->__invoke(++$done, $total);
            }
            $missingRows = MissingPages::rows($contents, $images->notShown);
            $counts = [];
            foreach ($contents->sections as $s => $section) {
                $counts[] = array_values(array_map(count(...), $pictures[$s] ?? []));
            }
            $plan = new PackPlan($counts, array_values(array_map(count(...), $loose)), count($missingRows));

            $path = ReceiptPack::dir().'/'.$pack->id.'.pdf';
            $writer = new PdfWriter(Storage::disk('local')->path($path));
            $org = Organization::query()->find($pack->org_id);
            $frame = new PageFrame($writer, $plan->pages, (string) $org?->name, $pack->period_label, $pack->version);
            CoverPage::draw($frame, $contents, $pack->created_at, $images->notShown);
            OverviewPages::draw($frame, $contents, $plan);
            foreach ($contents->sections as $s => $section) {
                foreach ($section->bookings as $b => $booking) {
                    BookingPages::booking($frame, $section, $booking, $plan->number[$s][$b], $pictures[$s][$b] ?? []);
                }
            }
            foreach ($contents->loose as $l => $receipt) {
                BookingPages::loose($frame, $receipt, $l + 1, $loose[$l] ?? []);
            }
            MissingPages::draw($frame, $contents, $plan, $missingRows);
            if ($frame->current() !== $plan->pages) {
                throw new RuntimeException('Belegpaket: Seitenplan stimmt nicht ('.$frame->current().' statt '.$plan->pages.')');
            }
            $size = $writer->finish();

            $figures = $contents->figures();
            $pack->forceFill([
                'status' => ReceiptPack::DONE, 'progress' => 100, 'path' => $path, 'size' => $size, 'pages' => $plan->pages, 'finished_at' => now(),
                'summary' => [
                    'bookings' => $figures->count, 'with_receipt' => $figures->withReceipt, 'no_receipt' => $figures->noReceipt,
                    'missing' => $figures->missingCount(), 'expenses' => $figures->expenses, 'income' => $figures->income,
                    'loose' => count($contents->loose), 'not_shown' => count($images->notShown),
                ],
            ])->save();
        } finally {
            File::deleteDirectory($work);
        }
    }
}
