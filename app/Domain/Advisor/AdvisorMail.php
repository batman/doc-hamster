<?php

declare(strict_types=1);

namespace App\Domain\Advisor;

/**
 * Whether docHamster can send mail (point 210.3, F29): a real mailer is configured in .env – not the `log` or
 * `array` default and not the example host of .env.example. Only then the login offers "Passwort vergessen?".
 */
final class AdvisorMail
{
    public static function ready(): bool
    {
        $mailer = config('mail.default');
        if (! is_string($mailer) || in_array($mailer, ['log', 'array'], true)) {
            return false;
        }
        if ($mailer !== 'smtp') {
            return true;
        }
        $host = config('mail.mailers.smtp.host');

        return is_string($host) && $host !== '' && ! str_ends_with($host, 'example.com');
    }
}
