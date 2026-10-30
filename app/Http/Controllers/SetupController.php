<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Environment\CheckResult;
use App\Infrastructure\Environment\Doctor;
use App\Setup\DatabaseConnector;
use App\Setup\EnvFile;
use App\Setup\OwnerAccount;
use App\Setup\SetupCode;
use App\Setup\SetupMode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Setup wizard in the browser, core steps (D-024, P1-9): setup code →
 * server check → database → finish. Each step is derived from what is
 * already done, so an interrupted setup simply continues. Owner account,
 * mail, cron and phone follow in P1-11, before "finish".
 */
final class SetupController
{
    private const UNLOCKED = 'setup.unlocked';

    public function __construct(private readonly EnvFile $env, private readonly SetupCode $code) {}

    public function welcome(Request $request): View|RedirectResponse
    {
        if (config(SetupMode::BLOCKED) === true || ! $this->env->isWritable()) {
            return view('setup.blocked');
        }

        if ($request->session()->get(self::UNLOCKED) === true) {
            return redirect()->route('setup.server');
        }

        return view('setup.welcome', ['codeReady' => $this->code->ensure(), 'codePath' => $this->code->displayPath()]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:64']]);

        if (! $this->code->matches($input['code'])) {
            return back()->withErrors(['code' => __('setup.wizard.code_wrong')]);
        }

        $request->session()->regenerate();
        $request->session()->put(self::UNLOCKED, true);
        // The address the installer uses is the fixed domain (D-010, passkeys bind to it).
        $this->env->set(['APP_URL' => $request->getSchemeAndHttpHost()]);

        return redirect()->route('setup.server');
    }

    public function server(Request $request, Doctor $doctor): View
    {
        $this->requireUnlocked($request);

        // Database and cron come in their own steps.
        $results = array_values(array_filter(
            $doctor->run(),
            static fn (CheckResult $result): bool => ! in_array($result->key, ['database', 'cron'], true),
        ));

        return view('setup.server', ['results' => $results, 'canContinue' => ! Doctor::hasFailures($results)]);
    }

    public function databaseForm(Request $request): View
    {
        $this->requireUnlocked($request);

        return view('setup.database', ['ready' => $this->databaseReady()]);
    }

    public function database(Request $request, DatabaseConnector $connector): RedirectResponse
    {
        $this->requireUnlocked($request);

        $data = $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:200'],
        ]);

        $credentials = [
            'host' => (string) $data['host'],
            'port' => (int) $data['port'],
            'database' => (string) $data['database'],
            'username' => (string) $data['username'],
            'password' => (string) ($data['password'] ?? ''),
        ];

        $result = $connector->connect($credentials);

        if ($result['ok'] === false) {
            return back()->withInput($request->except('password'))
                ->withErrors(['database' => __('setup.wizard.db_errors.'.$result['reason'])]);
        }

        $connection = $result['connection'];
        $this->env->set([
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $credentials['host'],
            'DB_PORT' => (string) $credentials['port'],
            'DB_DATABASE' => $credentials['database'],
            'DB_USERNAME' => $credentials['username'],
            'DB_PASSWORD' => $credentials['password'],
        ]);

        config([
            'database.default' => $connection,
            "database.connections.{$connection}.host" => $credentials['host'],
            "database.connections.{$connection}.port" => $credentials['port'],
            "database.connections.{$connection}.database" => $credentials['database'],
            "database.connections.{$connection}.username" => $credentials['username'],
            "database.connections.{$connection}.password" => $credentials['password'],
        ]);
        DB::purge($connection);

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            return back()->withErrors(['database' => __('setup.wizard.migrate_failed')]);
        }

        return redirect()->route('setup.finish');
    }

    public function finishPage(Request $request): View|RedirectResponse
    {
        $this->requireUnlocked($request);

        return $this->databaseReady()
            ? view('setup.finish', ['needsOwner' => ! OwnerAccount::exists()])
            : redirect()->route('setup.database');
    }

    public function finish(Request $request): RedirectResponse
    {
        $this->requireUnlocked($request);

        if (! $this->databaseReady()) {
            return redirect()->route('setup.database');
        }

        // Without a shell the owner can only be created here (FTP-only hosting).
        if (! OwnerAccount::exists()) {
            $data = $request->validate([
                'org' => ['required', 'string', 'max:150'],
                'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
                'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
            ]);
            OwnerAccount::create($data['org'], $data['username'], $data['password']);
        }

        $this->env->set(['DOCHAMSTER_INSTALLED' => 'true']);
        $this->code->delete();
        $request->session()->invalidate();

        return redirect()->route('login');
    }

    private function requireUnlocked(Request $request): void
    {
        abort_unless($request->session()->get(self::UNLOCKED) === true, 403);
    }

    private function databaseReady(): bool
    {
        try {
            return Schema::hasTable('migrations') && Schema::hasTable('users');
        } catch (Throwable) {
            return false;
        }
    }
}
