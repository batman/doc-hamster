<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Infrastructure\Environment\CheckResult;
use App\Infrastructure\Environment\CheckStatus;

trait RendersDoctorResults
{
    /**
     * @param  list<CheckResult>  $results
     */
    protected function renderDoctorResults(array $results): void
    {
        $rows = array_map(fn (CheckResult $result): array => [
            $this->statusLabel($result->status),
            __('doctor.checks.'.$result->key),
            __('doctor.details.'.$result->detail, $result->params),
        ], $results);

        $this->table([__('doctor.columns.status'), __('doctor.columns.check'), __('doctor.columns.detail')], $rows);
    }

    private function statusLabel(CheckStatus $status): string
    {
        $label = __('doctor.status.'.$status->value);

        return match ($status) {
            CheckStatus::Ok => '<info>'.$label.'</info>',
            CheckStatus::Warning => '<comment>'.$label.'</comment>',
            CheckStatus::Fail => '<error>'.$label.'</error>',
        };
    }
}
