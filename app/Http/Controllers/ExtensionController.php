<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Ocr\OcrLog;
use App\Domain\Ocr\OcrTextQuality;
use App\Domain\Org\CurrentOrg;
use App\Http\OcrAccess;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use App\Infrastructure\Extensions\Catalogue;
use App\Infrastructure\Extensions\Extension;
use App\Infrastructure\Extensions\Extensions;
use App\Jobs\Local\RecognizeText;
use App\Jobs\Net\InstallExtension;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Einstellungen → Erweiterungen (point 173): everything optional on one page – data packs (171: OCR languages)
 * install, update, switch off and remove; code modules are listed as "kommt später". Only catalogue entries
 * (Catalogue), never an address from the request. A346: after a language came in, documents whose text looks
 * broken (OcrTextQuality) can be read again.
 */
final class ExtensionController extends Controller
{
    public function show(Extensions $extensions, LocalOcr $ocr, CurrentOrg $org): View
    {
        $installed = $extensions->installed();
        $rows = [];
        foreach (Catalogue::all() as $key => $ext) {
            if (! $ext->listed()) {
                continue;
            }
            $rows[] = [
                'ext' => $ext,
                'installed' => $extensions->isInstalled($key),
                'version' => $installed[$key]['version'] ?? null,
                'enabled' => $installed[$key]['enabled'] ?? false,
                'updatable' => $extensions->updatable($key),
            ];
        }
        $extra = array_diff($ocr->installedLanguages(), LocalOcr::CORE_LANGUAGES) !== [];

        return view('settings.extensions', [
            'rows' => $rows,
            'languages' => $ocr->installedLanguages(),
            'broken' => $extra ? self::brokenCount($org->id()) : null,
        ]);
    }

    /** Install or update (173.2): the download runs right in this request in the net job (D-020). */
    public function install(Request $request, LocalOcr $ocr, string $key): RedirectResponse
    {
        $ext = Catalogue::find($key);
        abort_if($ext === null || ! $ext->installable(), 404);
        set_time_limit(900);
        ignore_user_abort(true);
        try {
            InstallExtension::dispatchSync($key);
        } catch (Throwable $e) {
            return back()->with('error', __('extensions.failed', ['reason' => mb_substr($e->getMessage(), 0, 200)]));
        }
        $this->changed($ext, $ocr);

        return back()->with('status', __('extensions.installed', ['name' => self::name($ext)]));
    }

    public function remove(LocalOcr $ocr, Extensions $extensions, string $key): RedirectResponse
    {
        $ext = Catalogue::find($key);
        abort_if($ext === null, 404);
        $extensions->remove($key);
        $this->changed($ext, $ocr);

        return back()->with('status', __('extensions.removed', ['name' => self::name($ext)]));
    }

    /** 171.2: an installed language can be switched off without deleting it. */
    public function toggle(Request $request, LocalOcr $ocr, Extensions $extensions, string $key): RedirectResponse
    {
        $ext = Catalogue::find($key);
        abort_if($ext === null, 404);
        $extensions->setEnabled($key, $request->boolean('enabled'));
        $this->changed($ext, $ocr);

        return back();
    }

    /** A346: the documents whose text looks broken wait again and are read with all chosen languages. */
    public function rerunBroken(Request $request, CurrentOrg $org): RedirectResponse
    {
        $ids = self::brokenIds();
        foreach (array_chunk($ids, 500) as $chunk) {
            Document::query()->whereKey($chunk)->toBase()->update(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0, 'ocr_rescan' => false]);
        }
        foreach ($ids as $id) {
            RecognizeText::dispatch($id);
        }
        OcrLog::record($org->id(), 'system', 'rerun', ['message' => (string) count($ids), 'user_id' => OcrAccess::userId($request)]);
        OcrProgress::forgetWaiting($org->id());
        Cache::forget(self::brokenKey($org->id()));

        return back()->with('status', __('ocr.rerun_started'));
    }

    /** The display name of an entry (OCR languages: "Texterkennung: Griechisch", 172: "Übersetzung: Griechisch → Deutsch"). */
    public static function name(Extension $ext): string
    {
        return match ($ext->group) {
            'ocr' => __('extensions.ocr_name', ['language' => __('extensions.languages.'.($ext->meta['lang'] ?? ''))]),
            'translate' => __('extensions.translate_name', ['from' => __('extensions.translate_languages.'.($ext->meta['from'] ?? '')), 'to' => __('extensions.translate_languages.'.($ext->meta['to'] ?? ''))]),
            default => __('extensions.items.'.$ext->key.'.name'),
        };
    }

    public static function text(Extension $ext): string
    {
        if ($ext->group === 'translate' && $ext->files === []) {
            // 172.4: a bundle over the English pivot names the pairs it installs.
            $names = array_map(static fn (string $key): string => '„'.self::name(Catalogue::find($key) ?? $ext).'“', $ext->requires);

            return __('extensions.translate_via', ['first' => $names[0] ?? '', 'second' => $names[1] ?? '']);
        }

        return match ($ext->group) {
            'ocr' => __('extensions.ocr_text'),
            'translate' => __('extensions.translate_text'),
            default => __('extensions.items.'.$ext->key.'.text'),
        };
    }

    /** Download size with everything installed along with it, e.g. "4,2 MB". */
    public static function size(Extension $ext): string
    {
        $bytes = [];
        $add = static function (Extension $e) use (&$add, &$bytes): void {
            $bytes[$e->key] = $e->size();
            foreach ($e->requires as $key) {
                $required = Catalogue::find($key);
                if ($required !== null && ! isset($bytes[$key])) {
                    $add($required);
                }
            }
        };
        $add($ext);

        return number_format(array_sum($bytes) / 1048576, 1, ',', '.').' MB';
    }

    private function changed(Extension $ext, LocalOcr $ocr): void
    {
        if ($ext->group === 'ocr') {
            $ocr->ensureCoreTessdata();
            Cache::forget(self::brokenKey(app(CurrentOrg::class)->id()));
        }
    }

    private static function brokenCount(string $orgId): int
    {
        return (int) Cache::remember(self::brokenKey($orgId), 600, static fn (): int => count(self::brokenIds()));
    }

    /**
     * Read documents of this organisation (global org scope) without an own language choice whose text looks broken.
     *
     * @return list<string>
     */
    private static function brokenIds(): array
    {
        $ids = [];
        Document::query()->where('ocr_status', 'done')->whereNotNull('ocr_text')->whereNull('ocr_langs')
            ->select(['id', 'ocr_text'])->lazyById(200)
            ->each(static function (Document $d) use (&$ids): void {
                if (OcrTextQuality::looksBroken((string) $d->getAttribute('ocr_text'))) {
                    $ids[] = (string) $d->id;
                }
            });

        return $ids;
    }

    private static function brokenKey(string $orgId): string
    {
        return 'extensions.ocr-broken.'.$orgId;
    }
}
