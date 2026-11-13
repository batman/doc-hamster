<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use App\Jobs\Net\UpdateSource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Owner-triggered update. The update itself is done by install.php, which swaps
 * the program files while nothing of the app is running: this controller only
 * places the installer in the web root and hands over with a one-time code.
 */
final class UpdateController
{
    private const PREVIEW_INFO = 'preview-installed.json';

    /** Progress file install.php writes into the web root during an update the pop-up started. */
    private const STATUS_FILE = 'install-status.json';

    public function show(Request $request): View
    {
        if ($request->query('updated') === '1') {
            @unlink($this->webRoot($request).'/'.self::STATUS_FILE);
        }

        return view('updates.show', [
            'current' => (string) config('dochamster.version'),
            // Before the click only what is known here: the installed version (and Main as the way back).
            'rows' => session('updates.versions') ?? $this->rows(false, null, []),
            'supported' => $this->supported($request),
            'updated' => $request->query('updated') === '1',
        ]);
    }

    /**
     * "Update" in the card head (task 109): asks GitHub once for the newest Main release and, for people
     * with the preview box or with a preview installed, for the preview branches. The page asks by Ajax
     * and puts the list into its card; without JavaScript it reloads.
     */
    public function check(Request $request, UpdateSource $source): JsonResponse|RedirectResponse
    {
        $preview = $this->previewInfo();
        $allPreviews = Gate::allows('system.update_preview');

        try {
            $latest = $source->latest(PHP_VERSION);
            $branches = $allPreviews || $preview !== null ? $source->previewBranches() : [];
        } catch (Throwable) {
            return $this->answer($request, ['rows' => $this->rows(false, null, []), 'failed' => true], fn () => back()->withErrors(['update' => __('updates.unreachable')]));
        }

        if (! $allPreviews) {
            // Without the preview box only the installed branch shows, for its own updates.
            $branches = array_values(array_filter($branches, fn (array $branch): bool => $branch['number'] === ($preview['number'] ?? null)));
        }
        // Kept for the install, which names the branch afterwards (point 6).
        $request->session()->put('updates.preview_list', $branches);

        $rows = $this->rows(true, $latest, $branches);
        $installed = collect($rows)->firstWhere('installed', true);
        $newer = is_array($installed) && $installed['newer'] !== '';

        return $this->answer($request, ['rows' => $rows, 'failed' => false], fn () => back()
            ->with('updates.versions', $rows)
            ->with('status', $newer ? null : __('updates.up_to_date')));
    }

    /**
     * One list of all versions: Main and every preview branch, the installed one marked (task 109).
     * "Aktualisieren" stays in the installed branch; "Wechseln" is the only way to another one.
     *
     * @param  array{version: string, date: string}|null  $latest
     * @param  list<array{number: int, title: string, branch: string, sha: string, date: string}>  $branches
     * @return list<array{kind: string, number: int, label: string, title: string, version: string, date: string, installed: bool, newer: string, gone: bool, switchable: bool, updatable: bool}>
     */
    private function rows(bool $asked, ?array $latest, array $branches): array
    {
        $current = (string) config('dochamster.version');
        $preview = $this->previewInfo();
        $previewRight = Gate::allows('system.update_preview');
        $installedDate = $this->installedDate();

        $rows = [[
            'kind' => 'main',
            'number' => 0,
            'label' => (string) __('updates.main'),
            'title' => (string) __('updates.main_hint'),
            'version' => $preview === null ? $current : (string) ($latest['version'] ?? ''),
            'date' => $preview === null ? $installedDate : (string) ($latest['date'] ?? ''),
            'installed' => $preview === null,
            'newer' => $preview === null && $latest !== null && version_compare($latest['version'], $current, '>') ? $latest['version'] : '',
            'gone' => false,
            // The way back to Main is always offered while a preview runs (A95), unless GitHub has no fitting release.
            'switchable' => $preview !== null && (! $asked || $latest !== null),
            'updatable' => true,
        ]];

        $listed = false;
        foreach ($branches as $branch) {
            $mine = $preview !== null && $branch['number'] === $preview['number'];
            $listed = $listed || $mine;
            $rows[] = [
                'kind' => 'preview',
                'number' => $branch['number'],
                'label' => '#'.$branch['number'].($branch['branch'] !== '' ? ' · '.$branch['branch'] : ''),
                'title' => $branch['title'],
                'version' => $mine ? $preview['sha'] : substr($branch['sha'], 0, 7),
                'date' => $mine ? $installedDate : $branch['date'],
                'installed' => $mine,
                'newer' => $mine && ! str_starts_with($branch['sha'], $preview['sha']) ? substr($branch['sha'], 0, 7) : '',
                'gone' => false,
                'switchable' => ! $mine && $previewRight,
                'updatable' => $previewRight,
            ];
        }

        if ($preview !== null && ! $listed) {
            $rows[] = [
                'kind' => 'preview',
                'number' => $preview['number'],
                'label' => '#'.$preview['number'].($preview['branch'] !== '' ? ' · '.$preview['branch'] : ''),
                'title' => $preview['title'],
                'version' => $preview['sha'],
                'date' => $installedDate,
                'installed' => true,
                'newer' => '',
                // Asked and not listed: the branch is no longer open for previews.
                'gone' => $asked,
                'switchable' => false,
                'updatable' => false,
            ];
            usort($rows, fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        }

        return $rows;
    }

    /** The day the installed program files were written (the VERSION file of the package), or ''. */
    private function installedDate(): string
    {
        $time = @filemtime(base_path('VERSION'));

        return $time === false ? '' : date('Y-m-d', $time);
    }

    /** "Aktualisieren" on Main: the newest release, only while Main is installed (an update never changes the branch). */
    public function install(Request $request, UpdateSource $source, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        if ($this->previewInfo() !== null) {
            return back()->withErrors(['update' => __('updates.stay_in_branch')]);
        }
        abort_unless($this->supported($request), 403);

        try {
            $latest = $source->latest(PHP_VERSION);
            $installer = $latest === null ? null : $source->installer($latest);
        } catch (Throwable) {
            return back()->withErrors(['update' => __('updates.unreachable')]);
        }

        if ($latest === null || $installer === null) {
            return back()->with('status', __('updates.up_to_date'));
        }

        return $this->handoff($request, $installer, $audit, $org, ['version' => $latest['version'], 'channel' => 'stable']);
    }

    /** "Wechseln" to Main: the way back from a preview branch (A95). */
    public function installMain(Request $request, UpdateSource $source, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        abort_unless($this->supported($request), 403);

        try {
            $latest = $source->latest(PHP_VERSION);
            $installer = $latest === null ? null : $source->installer($latest);
        } catch (Throwable) {
            return back()->withErrors(['update' => __('updates.unreachable')]);
        }

        if ($latest === null || $installer === null) {
            return back()->withErrors(['update' => __('updates.up_to_date')]);
        }

        return $this->handoff($request, $installer, $audit, $org, ['version' => $latest['version'], 'channel' => 'stable']);
    }

    public function installPreview(Request $request, UpdateSource $source, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'preview_pr' => ['required', 'integer', 'min:1'],
            'confirm_preview' => ['accepted'],
        ]);
        abort_unless($this->supported($request), 403);

        try {
            $preview = $source->preview((int) $data['preview_pr']);
            if (! version_compare(PHP_VERSION, $preview['php'], '>=')) {
                return back()->withErrors(['preview' => __('updates.preview_php')]);
            }
            $installer = $source->installer($preview);
        } catch (Throwable) {
            return back()->withErrors(['preview' => __('updates.preview_unavailable')]);
        }

        // Name and description of the branch, so the installed row can show them later without asking GitHub.
        $listed = collect((array) session('updates.preview_list', []))->firstWhere('number', $preview['pr']);
        Storage::disk('local')->put(self::PREVIEW_INFO, (string) json_encode([
            'number' => $preview['pr'],
            'sha' => $preview['commit_sha'],
            'branch' => is_array($listed) ? (string) ($listed['branch'] ?? '') : '',
            'title' => is_array($listed) ? (string) ($listed['title'] ?? '') : '',
        ]));

        return $this->handoff($request, $installer, $audit, $org, [
            'version' => $preview['version'],
            'channel' => 'preview',
            'pull_request' => $preview['pr'],
            'commit_sha' => $preview['commit_sha'],
        ]);
    }

    /**
     * The version list as HTML for the Ajax call, or the old page reload without JavaScript.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(): RedirectResponse  $fallback
     */
    private function answer(Request $request, array $data, callable $fallback): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['html' => view('updates._versions', $data + ['supported' => $this->supported($request)])->render()]) : $fallback();
    }

    /**
     * What the installed preview is, if the stored note matches the running version.
     *
     * @return array{number: int, sha: string, branch: string, title: string}|null
     */
    private function previewInfo(): ?array
    {
        if (preg_match('/^preview-(\d+)-([0-9a-f]+)$/', (string) config('dochamster.version'), $m) !== 1) {
            return null;
        }
        $info = json_decode((string) Storage::disk('local')->get(self::PREVIEW_INFO), true);
        $info = is_array($info) && (int) ($info['number'] ?? 0) === (int) $m[1] ? $info : [];

        return ['number' => (int) $m[1], 'sha' => $m[2], 'branch' => (string) ($info['branch'] ?? ''), 'title' => (string) ($info['title'] ?? '')];
    }

    /**
     * Places install.php and hands over. The page's pop-up asks by Ajax and gets the address; it then
     * runs the installer itself and follows its progress file. Without JavaScript, and for an installer
     * that writes no progress yet, the browser goes to install.php as before.
     *
     * @param  array<string, int|string>  $meta
     */
    private function handoff(Request $request, string $installer, AuditLog $audit, CurrentOrg $org, array $meta): JsonResponse|RedirectResponse
    {
        $webRoot = $this->webRoot($request);
        $code = strtoupper(bin2hex(random_bytes(10)));
        // An old progress file must never be taken for this run.
        @unlink($webRoot.'/'.self::STATUS_FILE);

        if (@file_put_contents($webRoot.'/install.php', $installer) === false
            || @file_put_contents(dirname($webRoot).'/dochamster-install-code.txt', $code."\n") === false) {
            @unlink($webRoot.'/install.php');

            return back()->withErrors(['update' => __('updates.not_writable')]);
        }

        $audit->record('update.started', orgId: $org->id(), userId: $org->membership()->user_id, meta: $meta, ip: $request->ip());

        $url = '/install.php?code='.$code.'&update=1';
        if ($request->expectsJson()) {
            return response()->json(['url' => $url, 'version' => (string) $meta['version'], 'progress' => str_contains($installer, self::STATUS_FILE)]);
        }

        return redirect()->to($url);
    }

    /** Only the layout install.php creates: app folder next to the web root. */
    private function supported(Request $request): bool
    {
        $webRoot = $this->webRoot($request);

        return dirname($webRoot) === dirname(base_path()) && is_writable($webRoot) && is_writable(dirname($webRoot));
    }

    private function webRoot(Request $request): string
    {
        return dirname($request->server->getString('SCRIPT_FILENAME'));
    }
}
