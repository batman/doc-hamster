<?php

declare(strict_types=1);

/*
| The Old Forge theme is self-contained: fonts ship with the app (CSP
| font-src 'self', offline PWA), day and night come from [data-theme]
| tokens, and no stylesheet may phone home.
*/

function themeRoot(): string
{
    return dirname(__DIR__, 3);
}

it('keeps every app.css url() local and existing', function (): void {
    $css = (string) file_get_contents(themeRoot().'/public/css/app.css');
    preg_match_all('#url\(["\']?([^"\')]+)["\']?\)#', $css, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $url) {
        expect($url)->not->toMatch('#^https?://|data:#', "{$url} is not a local file");

        $path = themeRoot().'/public/'.ltrim($url, '/');
        // Relative urls in css/ resolve against public/css/.
        if (! str_starts_with($url, '/')) {
            $path = themeRoot().'/public/css/'.$url;
        }

        expect(is_file($path))->toBeTrue("missing theme asset: {$url}");
    }
});

it('defines day and night token blocks', function (): void {
    $css = (string) file_get_contents(themeRoot().'/public/css/app.css');

    expect($css)->toContain('[data-theme="dark"]')
        ->toContain('[data-theme="light"]')
        ->toContain('--ember:')
        ->toContain('--ink:');
});

it('self-hosts the three type families', function (): void {
    $css = (string) file_get_contents(themeRoot().'/public/css/app.css');

    foreach (['Fraunces', 'JetBrains Mono', 'Inter Tight'] as $family) {
        expect($css)->toContain('font-family: "'.$family.'"');
    }

    foreach (glob(themeRoot().'/public/fonts/*.woff2') ?: [] as $file) {
        expect(filesize($file))->toBeGreaterThan(1024, "{$file} looks empty");
        expect((string) file_get_contents($file, false, null, 0, 4))->toBe('wOF2');
    }

    expect(glob(themeRoot().'/public/fonts/*.woff2'))->toHaveCount(6);
});

it('keeps the installer page self-contained and on-palette', function (): void {
    $installer = (string) file_get_contents(themeRoot().'/installer/install.php');

    expect($installer)->not->toMatch('#https?://fonts\.#')
        ->toContain('#0a0805')
        ->toContain('#f0e7d2')
        ->not->toContain('#ff7622');
});
