<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\ReceiptPack\PackBuilder;
use App\Domain\ReceiptPack\PackContents;
use App\Domain\ReceiptPack\PackPeriod;
use App\Domain\ReceiptPack\ReceiptPack;
use App\Http\Badges;
use App\Jobs\Local\BuildReceiptPack;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einstellungen → Export → Belegpaket (point 211, D-018): period + accounts → "Vorschau" (counts, missing) →
 * "Belegpaket erstellen" → download; at most three clicks. Small packs are built in the request, large ones by a job
 * (red badge when ready). Every pack is frozen and versioned per period.
 */
final class ReceiptPackController
{
    public function index(Request $request, CurrentOrg $org): View
    {
        $accounts = BankAccount::query()->orderByRaw("kind = 'cash'")->orderBy('name')->get();
        [$period, $preset] = $this->period($request);
        $chosen = $this->accounts($request, array_values(array_map('strval', $accounts->pluck('id')->all())));
        $preview = $request->boolean('preview') && $chosen !== [] ? PackContents::load($period, $chosen) : null;

        return view('settings.packs', [
            'accounts' => $accounts,
            'period' => $period,
            'preset' => $preset,
            'chosen' => $chosen,
            'preview' => $preview,
            'background' => $preview !== null && self::size($preview) > PackBuilder::DIRECT_LIMIT,
            'packs' => ReceiptPack::ofOrg($org->id())->latest()->limit(20)->get(),
            'accountNames' => $accounts->pluck('name', 'id')->all(),
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*' => ['string', Rule::in(BankAccount::query()->pluck('id')->all())],
        ]);
        $period = PackPeriod::of($data['from'], $data['to']);
        /** @var list<string> $accounts */
        $accounts = array_values(array_unique($data['accounts']));
        $userId = $org->membership()->user_id;
        $background = self::count($period, $accounts) > PackBuilder::DIRECT_LIMIT;
        $pack = ReceiptPack::start($org->id(), $userId, $period, $accounts, ReceiptPack::QUEUED);
        $audit->record('pack.created', orgId: $org->id(), userId: $userId, subject: $pack, meta: ['period' => $pack->period_label, 'version' => $pack->version, 'accounts' => count($accounts), 'background' => $background], ip: $request->ip());

        if ($background) {
            BuildReceiptPack::dispatch($pack->id);

            return redirect()->route('packs.index')->with('status', __('receipt_pack.queued'));
        }
        @set_time_limit(300);
        BuildReceiptPack::run($pack, $org);
        $pack->refresh();
        Badges::forget();

        return $pack->status === ReceiptPack::DONE
            ? redirect()->route('packs.index')->with('status', __('receipt_pack.created', ['name' => $pack->fileName()]))
            : redirect()->route('packs.index')->withErrors(['pack' => __('receipt_pack.failed')]);
    }

    public function download(Request $request, string $id, CurrentOrg $org, AuditLog $audit): Response
    {
        $pack = ReceiptPack::ofOrg($org->id())->whereKey($id)->firstOrFail();
        abort_unless($pack->isReady(), 404);
        $userId = $org->membership()->user_id;
        $audit->record('pack.downloaded', orgId: $org->id(), userId: $userId, subject: $pack, meta: ['file' => $pack->fileName()], ip: $request->ip());
        if ($pack->created_by === $userId && $pack->downloaded_at === null) {
            $pack->forceFill(['downloaded_at' => now()])->save();
            Badges::forget();
        }

        return Storage::disk('local')->download((string) $pack->path, $pack->fileName(), ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store']);
    }

    /**
     * The chosen period: a quick choice (last month/quarter/year) or from/to; default the last quarter.
     *
     * @return array{PackPeriod, string}
     */
    private function period(Request $request): array
    {
        $preset = (string) $request->query('preset', '');
        $from = $request->query('from');
        $to = $request->query('to');
        $date = '/^\d{4}-\d{2}-\d{2}$/';
        if ($preset === '' && is_string($from) && is_string($to) && preg_match($date, $from) === 1 && preg_match($date, $to) === 1 && $from <= $to) {
            return [PackPeriod::of($from, $to), ''];
        }
        $preset = in_array($preset, ['month', 'quarter', 'year'], true) ? $preset : 'quarter';

        return [PackPeriod::preset($preset) ?? PackPeriod::of(now()->startOfMonth()->toDateString(), now()->toDateString()), $preset];
    }

    /**
     * Ticked accounts (all when nothing is chosen yet).
     *
     * @param  list<string>  $all
     * @return list<string>
     */
    private function accounts(Request $request, array $all): array
    {
        if (! $request->has('accounts')) {
            return $all;
        }

        return array_values(array_intersect($all, array_map('strval', (array) $request->query('accounts', []))));
    }

    private static function size(PackContents $contents): int
    {
        return count($contents->loose) + $contents->figures()->count;
    }

    /**
     * @param  list<string>  $accounts
     */
    private static function count(PackPeriod $period, array $accounts): int
    {
        $bookings = Transaction::query()->whereIn('bank_account_id', $accounts)
            ->whereDate('booking_date', '>=', $period->from->toDateString())->whereDate('booking_date', '<=', $period->to->toDateString())->count();
        $loose = Document::query()->where('in_receipt_pack', true)
            ->whereRaw(Document::dateSql('doc').' between ? and ?', [$period->from->toDateString(), $period->to->toDateString()])->count();

        return $bookings + $loose;
    }
}
