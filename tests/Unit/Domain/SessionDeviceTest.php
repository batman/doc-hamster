<?php

declare(strict_types=1);

use App\Domain\Auth\SessionDevice;

it('reads browser and system from a user agent (point 208)', function (string $agent, ?string $browser, ?string $system): void {
    expect(SessionDevice::parse($agent))->toBe(['browser' => $browser, 'system' => $system]);
})->with([
    'firefox windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox', 'Windows'],
    'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'Safari', 'iPhone'],
    'chrome iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0 Mobile/15E148 Safari/604.1', 'Chrome', 'iPhone'],
    'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36 Edg/131.0', 'Edge', 'Windows'],
    'chrome android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36', 'Chrome', 'Android'],
    'safari mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'Safari', 'Mac'],
    'curl' => ['curl/8.5.0', null, null],
    'empty' => ['', null, null],
]);

it('marks phones and tablets as mobile', function (): void {
    expect(SessionDevice::isMobile('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Safari/604.1'))->toBeTrue()
        ->and(SessionDevice::isMobile('Mozilla/5.0 (Windows NT 10.0) Firefox/132.0'))->toBeFalse()
        ->and(SessionDevice::isMobile(null))->toBeFalse();
});

it('never shows the full IP (A374)', function (?string $ip, ?string $short): void {
    expect(SessionDevice::shortIp($ip))->toBe($short);
})->with([
    'ipv4' => ['192.0.2.123', '192.0.2.x'],
    'ipv6' => ['2001:db8:85a3::8a2e:370:7334', '2001:db8:85a3:…'],
    'ipv6 short' => ['2001:db8::1', '2001:db8:0:…'],
    'loopback v6' => ['::1', '0:0:0:…'],
    'garbage' => ['not-an-ip', null],
    'null' => [null, null],
]);
