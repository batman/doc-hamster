<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDoctorResults;
use App\Infrastructure\Environment\Doctor;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    use RendersDoctorResults;

    protected $signature = 'dochamster:doctor';

    protected $description = 'Check this server against the DocHamster minimum requirements and list available add-ons (D-010)';

    public function handle(Doctor $doctor): int
    {
        $results = $doctor->run();
        $this->renderDoctorResults($results);

        if (Doctor::hasFailures($results)) {
            $this->error(__('doctor.summary.failed'));

            return self::FAILURE;
        }

        $this->info(__('doctor.summary.ok'));

        return self::SUCCESS;
    }
}
