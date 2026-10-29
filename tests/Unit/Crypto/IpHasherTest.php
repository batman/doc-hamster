<?php

declare(strict_types=1);

use App\Infrastructure\Crypto\IpHasher;

it('hashes the same IP to the same value under one key', function (): void {
    $hasher = new IpHasher('key-one');

    expect($hasher->hash('192.0.2.10'))
        ->toBe($hasher->hash('192.0.2.10'))
        ->toHaveLength(64)
        ->not->toContain('192.0.2.10');
});

it('gives different values for different IPs and different keys', function (): void {
    $one = new IpHasher('key-one');
    $two = new IpHasher('key-two');

    expect($one->hash('192.0.2.10'))->not->toBe($one->hash('192.0.2.11'))
        ->and($one->hash('192.0.2.10'))->not->toBe($two->hash('192.0.2.10'));
});

it('is not a plain SHA-256 that could be reversed by trying all addresses', function (): void {
    expect((new IpHasher('key-one'))->hash('192.0.2.10'))
        ->not->toBe(hash('sha256', '192.0.2.10'));
});
