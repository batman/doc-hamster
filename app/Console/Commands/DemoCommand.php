<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * F48: a local demo of the app on SQLite with synthetic data, to check every screen in the browser without a real
 * installation. Writes `.env.demo` (git-ignored, like every .env.*) and `database/demo.sqlite`, then migrates fresh and
 * seeds DemoSeeder. `scripts/demo-server.php` serves it (php -S … scripts/demo-server.php, see .claude/launch.json).
 * Never on a server: it refuses when this installation runs in production.
 */
final class DemoCommand extends Command
{
    protected $signature = 'dochamster:demo {--port=8124 : Port of the local demo server}';

    protected $description = 'Build the local demo (SQLite, synthetic receipts and bookings)';

    public function handle(): int
    {
        if (app()->environment('production') && config('dochamster.installed') === true) {
            $this->error('Not on an installed production server.');

            return self::FAILURE;
        }
        $root = base_path();
        $database = $root.'/database/demo.sqlite';
        @unlink($database);
        touch($database);
        $port = (int) $this->option('port');
        file_put_contents($root.'/.env.demo', implode("\n", [
            'APP_NAME=docHamster',
            'APP_ENV=demo',
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG=true',
            'APP_URL=http://localhost:'.$port,
            'APP_LOCALE=de',
            'DOCHAMSTER_INSTALLED=true',
            'LOG_CHANNEL=single',
            'LOG_LEVEL=debug',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE="'.str_replace('\\', '/', $database).'"',
            'SESSION_DRIVER=file',
            'SESSION_SECURE_COOKIE=false',
            'SESSION_ENCRYPT=false',
            'CACHE_STORE=file',
            'QUEUE_CONNECTION=database',
            'MAIL_MAILER=log',
            '',
        ]));

        $php = (new PhpExecutableFinder)->find() ?: 'php';
        // This process loaded .env into the environment; the child must not inherit it, or it would win over .env.demo.
        $own = is_file($root.'/.env') ? array_keys(Dotenv::parse((string) file_get_contents($root.'/.env'))) : [];
        $env = [...array_fill_keys($own, false), 'APP_ENV' => 'demo'];
        $process = new Process([$php, 'artisan', 'migrate:fresh', '--seed', '--seeder='.DemoSeeder::class, '--force', '--no-interaction', '--env=demo'], $root, $env, null, 600);
        $process->run(fn (string $type, string $out) => $this->output->write($out));
        if (! $process->isSuccessful()) {
            $this->error('The demo could not be built.');

            return self::FAILURE;
        }
        $this->info('Demo ready: http://localhost:'.$port.' – sign-in: see Database\\Seeders\\DemoSeeder (USERNAME, PASSWORD).');

        return self::SUCCESS;
    }
}
