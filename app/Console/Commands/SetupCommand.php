<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDoctorResults;
use App\Infrastructure\Environment\Doctor;
use App\Setup\EnvFile;
use Illuminate\Console\Command;

/**
 * First-time setup of an instance: check requirements, create the app key,
 * migrate. Creating the owner account follows with the identity tables (P1-4).
 */
final class SetupCommand extends Command
{
    use RendersDoctorResults;

    protected $signature = 'dochamster:setup
        {--force : Continue even if a minimum requirement is not met}';

    protected $description = 'Set up DocHamster on this server: check requirements, app key, database';

    public function handle(Doctor $doctor, EnvFile $env): int
    {
        $this->info(__('setup.checking'));
        $results = $doctor->run();
        $this->renderDoctorResults($results);

        if (Doctor::hasFailures($results) && ! $this->option('force')) {
            $this->error(__('setup.requirements_failed'));

            return self::FAILURE;
        }

        if (blank(config('app.key'))) {
            $this->call('key:generate', ['--force' => true]);
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error(__('setup.migrate_failed'));

            return self::FAILURE;
        }

        // Same end as the browser wizard: the wizard is locked from now on (D-024).
        $env->set(['DOCHAMSTER_INSTALLED' => 'true']);

        $this->info(__('setup.done'));
        $this->line(__('setup.cron_hint', ['command' => '* * * * * php '.base_path('artisan').' schedule:run']));

        return self::SUCCESS;
    }
}
