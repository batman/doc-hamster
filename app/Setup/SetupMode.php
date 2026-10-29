<?php

declare(strict_types=1);

namespace App\Setup;

use Illuminate\Encryption\Encrypter;
use Throwable;

/**
 * Makes a fresh, not yet installed instance usable for the browser wizard
 * (D-024): it needs an app key for cookies and sessions before anything else,
 * and must not depend on the database it is about to set up.
 */
final class SetupMode
{
    /** Set when `.env` can't be written – the wizard can only explain the fix. */
    public const BLOCKED = 'dochamster.setup_blocked';

    public static function apply(EnvFile $env, string $examplePath): void
    {
        // Sessions, cache and rate limits in files until the database exists.
        config(['session.driver' => 'file', 'cache.default' => 'file']);

        if (filled(config('app.key'))) {
            return;
        }

        $key = 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc'));
        config(['app.key' => $key]);

        try {
            if (! $env->exists() && is_file($examplePath)) {
                copy($examplePath, $env->path());
            }

            $env->set(['APP_KEY' => $key]);
        } catch (Throwable) {
            // Key only lives for this request; sessions can't survive, so stop here.
            config([self::BLOCKED => true]);
        }
    }
}
