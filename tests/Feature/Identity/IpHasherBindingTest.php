<?php

declare(strict_types=1);

use App\Infrastructure\Crypto\IpHasher;
use Illuminate\Encryption\MissingAppKeyException;

it('keys IP hashes with the app key', function (): void {
    $key = config('app.key');

    expect(app(IpHasher::class)->hash('192.0.2.10'))
        ->toBe((new IpHasher(is_string($key) ? $key : ''))->hash('192.0.2.10'));
});

it('refuses to hash IPs without an app key', function (): void {
    config(['app.key' => '']);
    app()->forgetInstance(IpHasher::class);

    expect(fn () => app(IpHasher::class))->toThrow(MissingAppKeyException::class);
});
