<?php

declare(strict_types=1);

/*
| License check for everything we ship (D-022): every runtime Composer package
| needs a permissive license. Dev-only packages are not shipped and not checked.
| Packages with several licenses (SPDX "OR") pass if one of them is allowed.
| LGPL/MPL packages need an entry in $exceptions with the reason, mirrored in
| docs/DEPENDENCIES.md.
|
| Usage: composer licenses   (after composer install)
*/

$allowed = ['MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC', '0BSD', 'CC0-1.0', 'Unlicense'];

/** @var array<string, string> package => reason (D-022: unmodified library, listed in DEPENDENCIES.md) */
$exceptions = [];

$installed = __DIR__.'/../vendor/composer/installed.json';
if (! is_file($installed)) {
    fwrite(STDERR, "vendor/composer/installed.json not found – run composer install first.\n");
    exit(2);
}

/** @var array{packages: list<array{name: string, version?: string, license?: list<string>}>, dev-package-names?: list<string>} $data */
$data = json_decode((string) file_get_contents($installed), true, flags: JSON_THROW_ON_ERROR);
$dev = array_flip($data['dev-package-names'] ?? []);

$violations = [];
$checked = 0;
foreach ($data['packages'] as $package) {
    if (isset($dev[$package['name']])) {
        continue;
    }
    $checked++;
    $licenses = $package['license'] ?? [];

    if (array_intersect($licenses, $allowed) !== [] || isset($exceptions[$package['name']])) {
        continue;
    }

    $violations[] = sprintf('  %s %s: %s', $package['name'], $package['version'] ?? '?', $licenses === [] ? 'no license declared' : implode(' OR ', $licenses));
}

if ($violations !== []) {
    fwrite(STDERR, "License check failed (D-022) – not allowed in shipped code:\n".implode("\n", $violations)."\n");
    exit(1);
}

echo "License check passed: {$checked} runtime packages, all permissive (D-022).\n";
