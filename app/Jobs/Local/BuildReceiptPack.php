<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\ReceiptPack\PackBuilder;
use App\Domain\ReceiptPack\ReceiptPack;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds a large receipt pack in the background (point 211.5) on the local queue, with progress in its row; the
 * finished pack waits with a red badge under Einstellungen → Export. Runs as its creator in the pack's organisation.
 */
final class BuildReceiptPack implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $packId)
    {
        $this->onQueue(QueueName::Local->value);
    }

    public function handle(CurrentOrg $org): void
    {
        $pack = ReceiptPack::query()->find($this->packId);
        if ($pack === null || $pack->status !== ReceiptPack::QUEUED) {
            return;
        }
        self::run($pack, $org);
    }

    /** Also used directly by the request for small packs. */
    public static function run(ReceiptPack $pack, CurrentOrg $org): void
    {
        $membership = Membership::query()->where('org_id', $pack->org_id)->where('user_id', $pack->created_by)->first()
            ?? Membership::query()->where('org_id', $pack->org_id)->whereNull('revoked_at')->oldest()->first();
        if ($membership === null) {
            $pack->forceFill(['status' => ReceiptPack::FAILED, 'error' => 'no membership', 'finished_at' => now()])->save();

            return;
        }
        $previous = $org->isSet() ? $org->membership() : null;
        $org->set($membership);
        $pack->forceFill(['status' => ReceiptPack::RUNNING, 'progress' => 0])->save();
        try {
            $last = 0;
            PackBuilder::build($pack, static function (int $done, int $total) use ($pack, &$last): void {
                $percent = (int) floor($done * 95 / max(1, $total));
                if ($percent >= $last + 5) {
                    $last = $percent;
                    $pack->forceFill(['progress' => $percent])->save();
                }
            });
        } catch (Throwable $e) {
            report($e);
            $pack->forceFill(['status' => ReceiptPack::FAILED, 'error' => mb_substr($e->getMessage(), 0, 250), 'finished_at' => now()])->save();
        } finally {
            if ($previous !== null) {
                $org->set($previous);
            } else {
                $org->clear();
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        ReceiptPack::query()->whereKey($this->packId)->whereIn('status', [ReceiptPack::QUEUED, ReceiptPack::RUNNING])
            ->update(['status' => ReceiptPack::FAILED, 'finished_at' => now()]);
    }
}
