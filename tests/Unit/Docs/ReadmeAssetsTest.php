<?php

declare(strict_types=1);

/*
| The README embeds mascot artwork rendered from the app's own engine
| (scripts/hamster). These tests keep the embeds honest: every referenced
| file must exist, decode as an image, and stay small enough for a README.
*/

function readmeAssetPaths(): array
{
    $root = dirname(__DIR__, 3);
    $readme = file_get_contents($root.'/README.md');
    preg_match_all('#docs/assets/[A-Za-z0-9._-]+\.(gif|png)#', (string) $readme, $m);

    return array_values(array_unique($m[0]));
}

it('embeds at least one mascot asset', function (): void {
    expect(readmeAssetPaths())->not->toBeEmpty();
});

it('references only existing README assets', function (): void {
    $root = dirname(__DIR__, 3);

    foreach (readmeAssetPaths() as $rel) {
        expect(is_file($root.'/'.$rel))->toBeTrue("missing README asset: {$rel}");
    }
});

it('ships small, decodable README images', function (): void {
    $root = dirname(__DIR__, 3);
    $total = 0;

    foreach (readmeAssetPaths() as $rel) {
        $path = $root.'/'.$rel;
        $size = filesize($path);
        $total += $size;

        expect($size)->toBeLessThan(300 * 1024, "{$rel} exceeds 300 KiB");

        $info = getimagesize($path);

        expect($info)->not->toBeFalse("{$rel} does not decode as an image")
            ->and($info['mime'])->toBeIn(['image/gif', 'image/png']);
    }

    expect($total)->toBeLessThan(1024 * 1024, 'README assets exceed 1 MiB total');
});
