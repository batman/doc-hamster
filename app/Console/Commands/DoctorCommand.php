<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDoctorResults;
use App\Infrastructure\Environment\Doctor;
use App\Infrastructure\Environment\SpeedCheck;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    use RendersDoctorResults;

    protected $signature = 'dochamster:doctor
        {--local-only : Skip checks that need the database or network (offline use, D-020)}';

    protected $description = 'Check this server against the DocHamster minimum requirements and list available add-ons (D-010)';

    public function handle(Doctor $doctor): int
    {
        $results = $doctor->run(localOnly: (bool) $this->option('local-only'));
        $this->renderDoctorResults($results);
        // Point 164.6: speed tips (never a failure); they ask the database, so not in local-only mode.
        if (! $this->option('local-only')) {
            $this->renderDoctorResults(app(SpeedCheck::class)->run());
        }

        if (Doctor::hasFailures($results)) {
            $this->error(__('doctor.summary.failed'));

            return self::FAILURE;
        }

        $this->info(__('doctor.summary.ok'));

        return self::SUCCESS;
    }
}
