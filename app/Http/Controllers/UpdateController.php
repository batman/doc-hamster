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
            'available' => session('update.available'),
            'branchUpdates' => session('updates.branch_updates'),
            'branchGone' => (bool) session('updates.branch_gone'),
            'previewBranches' => session('updates.preview_branches'),
            'previewInstalled' => str_starts_with((string) config('dochamster.version'), 'preview-'),
            'previewInfo' => $this->previewInfo(),
            'supported' => $this->supported($request),
            'updated' => $request->query('updated') === '1',
        ]);
    }

    /**
     * "Auf neue Version prüfen": asks GitHub once. With a preview installed the
     * Main release always shows, as the way back (A36). The page asks by Ajax
     * and puts the list into its card; without JavaScript it reloads.
     */
    public function check(Request $request, UpdateSource $source): JsonResponse|RedirectResponse
    {
        $preview = $this->previewInfo();
        if ($preview !== null) {
            return $this->checkBranch($request, $source, $preview);
        }

        try {
            $latest = $source->latest(PHP_VERSION);
        } catch (Throwable) {
            return $this->answer($request, 'updates._releases', ['releases' => null, 'failed' => true], fn () => back()->withErrors(['update' => __('updates.unreachable')]));
        }

        $current = (string) config('dochamster.version');
        $onPreview = str_starts_with($current, 'preview-');
        $offer = $latest !== null && ($onPreview || version_compare($latest['version'], $current, '>'));
        $releases = $offer ? [['version' => $latest['version'], 'main' => $onPreview]] : [];

        return $this->answer($request, 'updates._releases', ['releases' => $releases, 'failed' => false], fn () => $offer
            ? back()->with('update.available', $latest['version'])
            : back()->with('status', __('updates.up_to_date')));
    }

    /**
     * A preview is installed: the only update is a newer state of that branch (A95). The way back to
     * Main is the tile under "Installiert".
     *
     * @param  array{number: int, sha: string, branch: string, title: string}  $preview
     */
    private function checkBranch(Request $request, UpdateSource $source, array $preview): JsonResponse|RedirectResponse
    {
        try {
            $listed = collect($source->previewBranches())->firstWhere('number', $preview['number']);
        } catch (Throwable) {
            return $this->answer($request, 'updates._releases', ['releases' => null, 'failed' => true], fn () => back()->withErrors(['update' => __('updates.unreachable')]));
        }

        $gone = ! is_array($listed);
        $newer = ! $gone && ! str_starts_with((string) $listed['sha'], $preview['sha']);
        $branches = $newer ? [$listed] : [];

        return $this->answer($request, 'updates._releases', ['releases' => [], 'branchUpdates' => $branches, 'branchGone' => $gone, 'failed' => false], fn () => back()
            ->with('updates.branch_updates', $branches)
            ->with('updates.branch_gone', $gone)
            ->with('status', $gone || $newer ? null : __('updates.up_to_date')));
    }

    public function checkPreviews(Request $request, UpdateSource $source): JsonResponse|RedirectResponse
    {
        try {
            $branches = $source->previewBranches();
        } catch (Throwable) {
            return $this->answer($request, 'updates._previews', ['branches' => null, 'failed' => true], fn () => back()->withErrors(['preview' => __('updates.unreachable')]));
        }
        // Kept for the install, which names the branch afterwards (point 6).
        $request->session()->put('updates.preview_list', $branches);

        return $this->answer($request, 'updates._previews', ['branches' => $branches, 'failed' => false], fn () => back()->with('updates.preview_branches', $branches));
    }

    public function install(Request $request, UpdateSource $source, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
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
            return back()->with('status', __('updates.up_to_date'));
        }

        return $this->handoff($request, $installer, $audit, $org, ['version' => $latest['version'], 'channel' => 'stable']);
    }

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

        // Name and description of the branch, so "Installiert" can show them later without asking GitHub.
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
     * The list for one card as HTML for the Ajax call, or the old page reload without JavaScript.
     *
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     * @param  callable(): RedirectResponse  $fallback
     */
    private function answer(Request $request, string $view, array $data, callable $fallback): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['html' => view($view, $data + ['supported' => $this->supported($request)])->render()]) : $fallback();
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
