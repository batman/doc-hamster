<?php

declare(strict_types=1);

/*
 * UI check (point 243, A434, A437; AGENTS.md "One UI framework"): every UI element comes from the styleguide /
 * FRAMEWORK.md. Fails when a Blade view
 *   - uses a CSS class that no stylesheet defines and no script uses as a hook (an element outside the framework),
 *   - sets inline styles other than CSS custom properties (style="--x: …" carries computed values, e.g. widths),
 *   - puts two filled main buttons into one Aktionszeile (.actions).
 * Also lists framework classes (public/css/core) the styleguide does not show yet – information, not a failure.
 * Usage: php scripts/check-ui.php
 */

$root = dirname(__DIR__);

/** @return list<string> */
function files(string $dir, string $suffix): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && str_ends_with($file->getPathname(), $suffix)) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
}

/** @return array<string, true> */
function cssClasses(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    // Only selector text: drop declaration blocks (also nested ones in @media/@container).
    $selectors = (string) preg_replace('/\{[^{}]*\}/', '{}', $css);
    preg_match_all('/\.(-?[A-Za-z_][\w-]*)/', $selectors, $m);

    return array_fill_keys($m[1], true);
}

$defined = [];
$core = [];
foreach (files($root.'/public/css', '.css') as $file) {
    $classes = cssClasses((string) file_get_contents($file));
    $defined += $classes;
    if (str_contains(str_replace('\\', '/', $file), '/public/css/core/')) {
        $core += $classes;
    }
}
// Generated stylesheets (tag colours …) and the styleguide's own classes.
$defined += cssClasses((string) file_get_contents($root.'/public/design/styleguide.html'));

$scripts = '';
foreach (files($root.'/public/js', '.js') as $file) {
    if (! str_contains(str_replace('\\', '/', $file), '/public/js/vendor/')) {
        $scripts .= file_get_contents($file);
    }
}
// Feature tests may look for a class too (a hook, not a look).
foreach (files($root.'/tests', '.php') as $file) {
    $scripts .= file_get_contents($file);
}
$hook = static fn (string $class): bool => preg_match('/[\'"`.\s]'.preg_quote($class, '/').'[\'"`\s,:)\]\[]/', $scripts) === 1;

$errors = [];
foreach (files($root.'/resources/views', '.blade.php') as $file) {
    $name = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
    $blade = (string) file_get_contents($file);
    // Comments out, their line breaks kept so the line numbers stay right.
    $plain = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', static fn (array $c): string => str_repeat("
", substr_count($c[0], "
")), $blade);
    $lines = static fn (int $offset): int => substr_count($plain, "\n", 0, $offset) + 1;

    preg_match_all('/(?<![:\w-])class="([^"]*)"/', $plain, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as [$value, $offset]) {
        // Blade output and directives inside the attribute are left out; the literal parts are checked.
        $literal = (string) preg_replace(['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s', '/@(if|unless|isset|class)\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/s', '/@(endif|endunless|endisset|else)\b/'], ' ', $value);
        foreach (preg_split('/\s+/', trim($literal)) ?: [] as $class) {
            if ($class === '' || preg_match('/^[A-Za-z_][\w-]*$/', $class) !== 1 || str_ends_with($class, '-')) {
                continue;
            }
            if (! isset($defined[$class]) && ! $hook($class)) {
                $errors[] = "{$name}:{$lines($offset)} class \"{$class}\" is not part of the framework (no stylesheet defines it)";
            }
        }
    }

    preg_match_all('/(?<![:\w-])style="([^"]*)"/', $plain, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as [$value, $offset]) {
        $literal = trim((string) preg_replace(['/\{\{.*?\}\}/s', '/@\w+\s*\([^)]*\)/'], 'X', $value));
        foreach (array_filter(array_map('trim', explode(';', $literal))) as $declaration) {
            if (! str_starts_with($declaration, '--')) {
                $errors[] = "{$name}:{$lines($offset)} inline style \"{$declaration}\" – use a framework class (only --custom-properties may be set inline)";
            }
        }
    }

    // Aktionszeile: at most one filled main button.
    preg_match_all('/<div class="actions[^"]*">(.*?)<\/div>/s', $plain, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as [$row, $offset]) {
        preg_match_all('/class="([^"]*)"/', $row, $c);
        $main = 0;
        foreach ($c[1] as $classes) {
            $list = preg_split('/\s+/', $classes) ?: [];
            if (array_intersect($list, ['button', 'button-small']) !== [] && array_intersect($list, ['button-quiet', 'button-danger', 'button-link', 'icon-button']) === []) {
                $main++;
            }
        }
        if ($main > 1) {
            $errors[] = "{$name}:{$lines($offset)} Aktionszeile with {$main} main buttons – at most one may be filled";
        }
    }
}

$styleguide = (string) file_get_contents($root.'/public/design/styleguide.html');
$missing = array_values(array_filter(array_keys($core), static fn (string $c): bool => ! str_contains($styleguide, $c) && ! str_starts_with($c, 'is-') && ! str_starts_with($c, 'has-')));
sort($missing);

if ($missing !== []) {
    fwrite(STDOUT, 'Info: '.count($missing)." framework classes are not shown in the styleguide yet.\n");
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n".count($errors)." UI problem(s). Rule: every element comes from the styleguide (docs/design/FRAMEWORK.md).\n");
    exit(1);
}
fwrite(STDOUT, "UI check passed.\n");
