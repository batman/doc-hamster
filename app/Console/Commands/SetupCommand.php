<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDoctorResults;
use App\Infrastructure\Environment\CheckResult;
use App\Infrastructure\Environment\Doctor;
use App\Setup\DatabaseConnector;
use App\Setup\EnvFile;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shell installation (D-024, AK-4.9.7, P1-10): the same steps as the browser
 * wizard in the terminal – requirements, app key, address, database (asks for
 * the credentials if the configured ones don't work), tables, done.
 * Owner account, mail and phone follow with P1-4/P1-11.
 */
final class SetupCommand extends Command
{
    use RendersDoctorResults;

    private const MAX_DB_ATTEMPTS = 3;

    protected $signature = 'dochamster:setup
        {--force : Continue even if a minimum requirement is not met}';

    protected $description = 'Set up DocHamster on this server: requirements, app key, address, database';

    public function handle(Doctor $doctor, EnvFile $env, DatabaseConnector $connector): int
    {
        $this->info(__('setup.checking'));
        // Database and cron are set up below / afterwards.
        $results = array_values(array_filter(
            $doctor->run(),
            static fn (CheckResult $result): bool => ! in_array($result->key, ['database', 'cron'], true),
        ));
        $this->renderDoctorResults($results);

        if (Doctor::hasFailures($results) && ! $this->option('force')) {
            $this->error(__('setup.requirements_failed'));

            return self::FAILURE;
        }

        if (! $env->isWritable()) {
            $this->error(__('setup.env_not_writable', ['path' => $env->path()]));

            return self::FAILURE;
        }

        if (! $env->exists() && is_file(base_path('.env.example'))) {
            copy(base_path('.env.example'), $env->path());
        }

        if (blank(config('app.key'))) {
            $key = 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc'));
            $env->set(['APP_KEY' => $key]);
            config(['app.key' => $key]);
        }

        $this->askForAddress($env);

        if (! $this->databaseWorks() && ! $this->askForDatabase($env, $connector)) {
            $this->error(__('setup.db_failed'));

            return self::FAILURE;
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error(__('setup.migrate_failed'));

            return self::FAILURE;
        }

        // Same end as the browser wizard: the wizard is locked from now on (D-024).
        $env->set(['DOCHAMSTER_INSTALLED' => 'true']);

        $this->info(__('setup.done'));
        $this->line(__('setup.cron_hint', [
            'command' => '* * * * * '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' schedule:run >/dev/null 2>&1',
        ]));

        return self::SUCCESS;
    }

    /**
     * The fixed domain passkeys bind to (D-010) – asked once, interactive runs only.
     */
    private function askForAddress(EnvFile $env): void
    {
        if (! $this->input->isInteractive()) {
            return;
        }

        $current = (string) config('app.url');
        $url = rtrim((string) $this->ask(__('setup.ask_url'), $current), '/');

        if (! str_starts_with($url, 'https://')) {
            $this->warn(__('setup.url_not_https'));
        }

        if ($url !== '' && $url !== $current) {
            $env->set(['APP_URL' => $url]);
            config(['app.url' => $url]);
        }
    }

    private function databaseWorks(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function askForDatabase(EnvFile $env, DatabaseConnector $connector): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        $this->line(__('setup.ask_db_intro'));
        $default = config('database.connections.'.config('database.default'));
        $default = is_array($default) ? $default : [];

        for ($attempt = 1; $attempt <= self::MAX_DB_ATTEMPTS; $attempt++) {
            $credentials = [
                'host' => (string) $this->ask(__('setup.wizard.db_host'), (string) ($default['host'] ?? 'localhost')),
                'port' => (int) $this->ask(__('setup.wizard.db_port'), (string) ($default['port'] ?? '3306')),
                'database' => (string) $this->ask(__('setup.wizard.db_name'), (string) ($default['database'] ?? 'dochamster')),
                'username' => (string) $this->ask(__('setup.wizard.db_user'), (string) ($default['username'] ?? '')),
                'password' => (string) $this->secret(__('setup.wizard.db_password')),
            ];

            $result = $connector->connect($credentials);

            if ($result['ok'] === true) {
                $connection = $result['connection'];
                $env->set([
                    'DB_CONNECTION' => $connection,
                    'DB_HOST' => $credentials['host'],
                    'DB_PORT' => (string) $credentials['port'],
                    'DB_DATABASE' => $credentials['database'],
                    'DB_USERNAME' => $credentials['username'],
                    'DB_PASSWORD' => $credentials['password'],
                ]);
                config(['database.default' => $connection]);
                foreach (['host', 'port', 'database', 'username', 'password'] as $field) {
                    config(["database.connections.{$connection}.{$field}" => $credentials[$field]]);
                }
                DB::purge($connection);

                return true;
            }

            $this->error(__('setup.wizard.db_errors.'.$result['reason']));
            $default = $credentials;
        }

        return false;
    }
}
