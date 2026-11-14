<?php

declare(strict_types=1);

namespace App\View;

use InvalidArgumentException;

/**
 * Point 164.4: `<x-icon name="…" />` costs about 0.4 ms as a Blade component – a list of 200 documents spent most of
 * its time there. Blade now compiles the tag into one call of svg() (same output as components/icon.blade.php);
 * each icon file is read once per request.
 */
final class Icon
{
    /** @var array<string, string> */
    private static array $svgs = [];

    public static function svg(string $name): string
    {
        if (isset(self::$svgs[$name])) {
            return self::$svgs[$name];
        }
        // Tabler icon from resources/icons/tabler, inlined so it takes the text colour (currentColor).
        $path = resource_path('icons/tabler/'.$name.'.svg');
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1 || ! is_file($path)) {
            throw new InvalidArgumentException("Unknown icon: {$name}");
        }

        return self::$svgs[$name] = str_replace('<svg ', '<svg class="icon" aria-hidden="true" focusable="false" ', trim((string) file_get_contents($path)));
    }

    /** Blade pre-compiler: `<x-icon name="pencil" />` and `<x-icon :name="$expr" />` become a plain call. */
    public static function compile(string $template): string
    {
        if (! str_contains($template, '<x-icon')) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/<x-icon\s+(?:name="([a-z0-9-]+)"|:name="([^"]*)")\s*\/>/',
            static fn (array $m): string => '<?php echo \\'.self::class.'::svg('.($m[1] !== '' ? "'".$m[1]."'" : $m[2]).'); ?>',
            $template,
        );
    }
}
