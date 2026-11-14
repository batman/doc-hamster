<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;

/**
 * Menu windows (point 156, A331): the display a person chose per menu window ("list" or "tiles"), kept in the plain
 * cookie `dh_menu_views` ("settings:tiles|settings.bank:list", written by public/js/core/menu-window.js). The server
 * draws the window in it, so nothing jumps. Not secret, so not encrypted (bootstrap/app.php), like CardFolds.
 */
final class MenuViews
{
    public const string COOKIE = 'dh_menu_views';

    public const array DISPLAYS = ['list', 'tiles'];

    /** The display of the menu window `$key`: the person's own choice, else the module's default. */
    public static function display(string $key, string $default, ?Request $request = null): string
    {
        $raw = ($request ?? request())->cookies->get(self::COOKIE);
        $chosen = self::parse(is_string($raw) ? $raw : '')[$key] ?? null;

        return $chosen ?? (in_array($default, self::DISPLAYS, true) ? $default : 'list');
    }

    /** @return array<string, string> key => display, unknown displays dropped */
    public static function parse(string $raw): array
    {
        $views = [];
        foreach (explode('|', $raw) as $entry) {
            $at = strrpos($entry, ':');
            if ($at === false || $at === 0) {
                continue;
            }
            $display = substr($entry, $at + 1);
            if (in_array($display, self::DISPLAYS, true)) {
                $views[substr($entry, 0, $at)] = $display;
            }
        }

        return $views;
    }
}
