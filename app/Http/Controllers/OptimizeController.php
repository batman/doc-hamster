<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Folder;
use App\Domain\Log\LogEntry;
use App\Domain\Optimize\OptimizeQueue;
use App\Domain\Optimize\OptimizeSettings;
use App\Domain\Optimize\OriginalKeeper;
use App\Domain\Org\CurrentOrg;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Point 236: Einstellungen → Dokumentgröße optimieren – on/off, the threshold per page, where the originals go, and
 * the progress (A410). Every setting saves at once (data-autosave); switched on, the work starts by itself.
 */
final class OptimizeController extends Controller
{
    public function show(CurrentOrg $org): View
    {
        $organization = $org->organization();

        return view('settings.optimize', [
            'settings' => OptimizeSettings::of($organization),
            'counts' => OptimizeQueue::counts($organization),
            'folders' => Folder::flat(),
        ]);
    }

    public function save(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'on' => ['required', 'boolean'],
            'mb' => ['required', 'numeric', 'min:'.OptimizeSettings::MB_MIN, 'max:'.OptimizeSettings::MB_MAX],
            'original' => ['required', Rule::in([OptimizeSettings::TRASH, OptimizeSettings::ARCHIVE])],
            'folder' => ['nullable', 'string', Rule::in(Folder::targetIds())],
        ]);
        $organization = $org->organization();
        $before = OptimizeSettings::of($organization);
        $on = (bool) $data['on'];
        $mb = OptimizeSettings::clampMb((float) $data['mb']);
        // An empty pick ("Neuer Ordner Originale") keeps the folder made for it earlier.
        $folder = is_string($data['folder'] ?? null) && $data['folder'] !== '' ? $data['folder'] : $before['folder'];
        OptimizeSettings::save($organization, ['on' => $on, 'mb' => $mb, 'original' => $data['original'], 'folder' => $folder]);
        // The archive without a folder picked: "Originale" is created (or the saved one kept).
        if ($data['original'] === OptimizeSettings::ARCHIVE) {
            OriginalKeeper::archiveFolder($organization);
        }
        if ($mb !== $before['mb']) {
            OptimizeQueue::thresholdChanged($organization);
        }
        if ($on !== $before['on']) {
            LogEntry::write('general', 'event', (string) __($on ? 'optimize.log.on' : 'optimize.log.off'), 'optimize.switched', $organization->id, $request->user()?->getAuthIdentifier());
        }
        if ($on) {
            OptimizeQueue::fill($organization);
        }
        $message = __('optimize.saved');

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('status', $message);
    }

    /** The progress figures for the page (polled while files wait). */
    public function status(CurrentOrg $org): JsonResponse
    {
        $counts = OptimizeQueue::counts($org->organization());

        return response()->json([...$counts, 'saved_text' => self::size($counts['saved'])]);
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? number_format($bytes / 1024 ** 3, 1, ',', '.').' GB'
            : number_format($bytes / 1024 ** 2, 1, ',', '.').' MB';
    }
}
