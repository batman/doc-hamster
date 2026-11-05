<?php

declare(strict_types=1);

namespace App\Domain\Printing;

use RuntimeException;

/**
 * The download that sets up the printer on Windows. It is the readable file
 * clients/windows/Hamster-Drucker-einrichten.cmd from the repository; only
 * the server address and the one-time code are filled in.
 */
final class WindowsInstaller
{
    public const FILENAME = 'Hamster-Drucker-einrichten.cmd';

    public static function build(string $serverUrl, string $code): string
    {
        $template = @file_get_contents(base_path('clients/windows/'.self::FILENAME));
        if ($template === false) {
            throw new RuntimeException('Windows printer installer is missing.');
        }

        // Only characters that can't break out of the single-quoted PowerShell strings.
        if (preg_match('#^https?://[A-Za-z0-9.\-:\[\]/_~%]+$#', $serverUrl) !== 1 || preg_match('/^[A-Za-z0-9]+$/', $code) !== 1) {
            throw new RuntimeException('Unexpected server address or code for the printer installer.');
        }

        $script = strtr($template, [
            '__DOCHAMSTER_URL__' => rtrim($serverUrl, '/'),
            '__PAIRING_CODE__' => $code,
        ]);

        // cmd.exe needs Windows line ends to find its labels.
        return (string) preg_replace("/\r?\n/", "\r\n", $script);
    }
}
