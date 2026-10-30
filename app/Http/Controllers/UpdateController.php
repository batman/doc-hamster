<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use App\Jobs\Net\UpdateSource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Owner-triggered update. The update itself is done by install.php, which swaps
 * the program files while nothing of the app is running: this controller only
 * places the installer in the web root and hands over with a one-time code.
 */
final class UpdateController
{
    public function show(Request $request): View
    {
        return view('updates.show', [
            'current' => (string) config('dochamster.version'),
            'available' => session('update.available'),
            'supported' => $this->supported($request),
        ]);
    }

    public function check(UpdateSource $source): RedirectResponse
    {
        try {
            $latest = $source->latest(PHP_VERSION);
        } catch (Throwable) {
            return back()->withErrors(['update' => __('updates.unreachable')]);
        }

        if ($latest === null || ! version_compare($latest['version'], (string) config('dochamster.version'), '>')) {
            return back()->with('status', __('updates.up_to_date'));
        }

        return back()->with('update.available', $latest['version']);
    }

    public function install(Request $request, UpdateSource $source, AuditLog $audit, CurrentOrg $org): RedirectResponse
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

        $webRoot = $this->webRoot($request);
        $code = strtoupper(bin2hex(random_bytes(10)));

        if (@file_put_contents($webRoot.'/install.php', $installer) === false
            || @file_put_contents(dirname($webRoot).'/dochamster-install-code.txt', $code."\n") === false) {
            @unlink($webRoot.'/install.php');

            return back()->withErrors(['update' => __('updates.not_writable')]);
        }

        $audit->record('update.started', orgId: $org->id(), userId: $org->membership()->user_id, meta: ['version' => $latest['version']], ip: $request->ip());

        return redirect()->to('/install.php?code='.$code);
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
