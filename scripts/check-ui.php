<?php

declare(strict_types=1);

/*
 * UI check (point 243, A434, A437; AGENTS.md "One UI framework"): every UI element comes from the styleguide /
 * FRAMEWORK.md. Fails when a Blade view
 *   - uses a CSS class that no stylesheet defines and no script uses as a hook (an element outside the framework),
 *   - has a style attribute (the CSP blocks every one the server writes; scripts set computed values instead),
 *   - puts two filled main buttons into one Aktionszeile (.actions).
 * Also lists framework classes (public/css/core) the styleguide (every styleguide*.html page + styleguide.js) does not show yet –
 * information, not a failure; the goal is 0.
 * Usage: php scripts/check-ui.php [--list]   (--list prints those classes with their stylesheet)
 */

$root = dirname(__DIR__);

/*
 * Framework classes the styleguide does not need to show (A437): no building blocks of their own, but states and helper
 * parts that only scripts set or switch on, and the layout debug overlay. A new entry needs a reason here.
 */
const STATE_CLASSES = [
    'can-size',          // .windows: the window row may be resized (core/window-edge.js)
    'fit-probe',         // off-screen box that measures row symbols (modules/documents-rows.js)
    'layout-probe',      // layout debug overlay (core/layout-debug.js)
    'more-left',         // html: content scrolled sideways, shadow on that side (core/scroll-hint.js)
    'more-right',
    'nav-open',          // html: phone menu drawer is open (core/menu.js)
    'nav-backdrop',      // dark backdrop behind the open drawer, only shown with nav-open
    'nav-wall',          // drag edge of the main menu, built by core/menu.js
    'pick-placeholder',  // dashed placeholder while a row is dragged (core/sortable.js)
    'show-picks',        // html: "Auswählen" is on, row checkboxes show (modules/documents-pick.js)
    'switcher-select',   // select a too narrow Umschalter turns into (core/button-choice.js)
];

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
    $path = str_replace('\\', '/', $file);
    if (str_contains($path, '/public/css/core/')) {
        $core += array_map(static fn (): string => substr($path, (int) strpos($path, 'public/css/')), $classes);
    }
}
// Batch 17: the styleguide is an index plus one page per category (public/design/styleguide*.html), all sharing
// styleguide.css and styleguide.js – every page counts.
$styleguidePages = glob($root.'/public/design/styleguide*.html') ?: [];
$styleguide = implode('', array_map(static fn (string $file): string => (string) file_get_contents($file), $styleguidePages))
    .file_get_contents($root.'/public/design/styleguide.js');
// Generated stylesheets (tag colours …) and the styleguide's own classes.
$defined += cssClasses((string) file_get_contents($root.'/public/design/styleguide.css').$styleguide);

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
    $plain = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', static fn (array $c): string => str_repeat('
', substr_count($c[0], '
')), $blade);
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

    // No style attributes at all: the CSP (style-src 'self', SecurityHeaders) blocks them in the browser, so they
    // silently do nothing. Values computed per element are set by script (element.style), which the CSP allows.
    preg_match_all('/(?<![:\w-])style="([^"]*)"/', $plain, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as [$value, $offset]) {
        $errors[] = "{$name}:{$lines($offset)} style attribute \"{$value}\" – the CSP blocks it; use a framework class (or set it from script)";
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

$missing = array_values(array_filter(array_keys($core), static fn (string $c): bool => ! str_contains($styleguide, $c) && ! str_starts_with($c, 'is-') && ! str_starts_with($c, 'has-') && ! in_array($c, STATE_CLASSES, true)));
sort($missing);

if ($missing !== []) {
    fwrite(STDOUT, 'Info: '.count($missing)." framework classes are not shown in the styleguide yet.\n");
    if (in_array('--list', $argv, true)) {
        foreach ($missing as $class) {
            fwrite(STDOUT, "  .{$class}  ({$core[$class]})\n");
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n".count($errors)." UI problem(s). Rule: every element comes from the styleguide (docs/design/FRAMEWORK.md).\n");
    exit(1);
}
fwrite(STDOUT, "UI check passed.\n");
