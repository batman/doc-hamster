<?php

declare(strict_types=1);

namespace EgressFixtures\Good;

/*
| Local-only lookalikes: variable paths, local literals and non-network
| wrappers. The D-020 PHPStan rules must stay silent on all of them.
*/
final class GoodProbe
{
    public function run(string $path): void
    {
        file_get_contents($path);
        file_get_contents('/etc/hostname');
        file_get_contents('php://stdin');
        fopen('php://temp', 'r+');
        file('/etc/hosts');
        copy($path, $path.'.bak');
    }
}
